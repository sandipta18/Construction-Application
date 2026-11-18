<?php

namespace Drupal\sthira;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * The "AI" layer, in demo mode: rule-based scoring and checks, no API calls.
 *
 * Every public method here is the seam where a real model could be plugged in
 * later; the rest of the app only consumes the arrays these return.
 */
class Assistant {

  const SEVERITY_ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];

  public function __construct(protected Repository $repo, protected Workflow $workflow) {}

  /**
   * Ranks vendors for a request and explains the top pick.
   */
  public function rankVendors(array $r): array {
    $quotes = $this->repo->quotesForItem((int) $r['item_id']);
    if (!$quotes) {
      return [];
    }
    $qty = (float) $r['qty'];
    $unit = $r['unit'];
    $rates = array_map(fn($q) => (float) $q['rate'], $quotes);
    $min = min($rates);
    $fastest = min(array_map(fn($q) => (int) $q['lead_days'], $quotes));
    $daysToNeed = max(0, (int) floor((strtotime($r['needed_by'] ?: 'today') - strtotime('today')) / 86400));

    foreach ($quotes as &$q) {
      $priceScore = $min / (float) $q['rate'] * 100;
      $score = 0.45 * $priceScore + 0.30 * (float) $q['on_time'] + 0.25 * ((float) $q['rating'] / 5 * 100);
      $q['late'] = (int) $q['lead_days'] > $daysToNeed;
      if ($q['late']) {
        $score -= 12;
      }
      $q['score'] = (int) round($score);
      $q['total_raw'] = round($qty * (float) $q['rate']);
    }
    unset($q);
    usort($quotes, fn($a, $b) => $b['score'] <=> $a['score']);

    $cheapest = NULL;
    foreach ($quotes as $q) {
      if ((float) $q['rate'] == $min) {
        $cheapest = $q;
        break;
      }
    }
    $best = $quotes[0];
    if ((float) $best['rate'] == $min) {
      $reason = 'Lowest price, and on time in ' . $best['on_time'] . '% of orders.';
    }
    else {
      $diff = (float) $best['rate'] - $min;
      $pct = round($diff / $min * 100, 1);
      $reason = $pct . '% dearer than ' . $cheapest['name'] . ', but on time in ' . $best['on_time'] . '% of orders vs ' . $cheapest['on_time'] . '%. The safer buy when the site can\'t wait.';
    }
    if (!$best['late']) {
      $reason .= ' Arrives before the needed-by date.';
    }
    else {
      $reason .= ' Note: its ' . $best['lead_days'] . '-day lead time may miss the needed-by date.';
    }

    $out = [];
    foreach ($quotes as $i => $q) {
      $tags = [];
      if ((float) $q['rate'] == $min) {
        $tags[] = 'Cheapest';
      }
      if ((int) $q['lead_days'] === $fastest) {
        $tags[] = 'Fastest';
      }
      if ($q['late']) {
        $tags[] = 'May miss date';
      }
      $out[] = [
        'vendor_id' => (int) $q['vendor_id'],
        'name' => $q['name'],
        'city' => $q['city'],
        'rate_raw' => (float) $q['rate'],
        'rate' => Format::inr($q['rate']) . '/' . Format::per($unit),
        'total' => Format::short($q['total_raw']),
        'total_raw' => $q['total_raw'],
        'lead' => $q['lead_days'] . ' days',
        'lead_days' => (int) $q['lead_days'],
        'on_time' => (int) $q['on_time'],
        'rating' => $q['rating'],
        'score' => $q['score'],
        'tags' => $tags,
        'reason' => $i === 0 ? $reason : '',
      ];
    }
    return $out;
  }

  /**
   * "Where to act next": rule-based checks across the user's projects.
   */
  public function insights(AccountInterface $a): array {
    $pids = $this->repo->accessibleProjectIds($a);
    $projects = $this->repo->projects($pids);
    $items = $this->repo->items();
    $out = [];

    // 1. Low stock at site.
    foreach ($this->repo->stock(NULL, $pids) as $s) {
      $use = (float) $s['daily_use'];
      if ($use <= 0) {
        continue;
      }
      $days = (float) $s['qty'] / $use;
      if ($days >= 5 || $this->repo->hasOpenRequest((int) $s['project_id'], (int) $s['item_id'])) {
        continue;
      }
      $item = $items[$s['item_id']];
      $project = $projects[$s['project_id']];
      $suggest = (int) (ceil($use * 14 / 10) * 10);
      $out[] = [
        'type' => 'stock',
        'tag' => 'Stock',
        'severity' => $days < 3 ? 'critical' : 'warning',
        'icon' => 'box',
        'title' => $this->shortItem($item['name']) . ' is running low at ' . $this->shortProject($project['name']),
        'text' => 'Only ' . Format::qty($s['qty']) . ' ' . $item['unit'] . ' left, about ' . max(1, (int) round($days)) . ' days\' worth. Order about ' . number_format($suggest) . ' ' . $item['unit'] . ' soon.',
        'action' => [
          'label' => 'Raise request',
          'url' => Url::fromRoute('sthira.request_new', [], ['query' => ['project' => $s['project_id'], 'item' => $s['item_id'], 'qty' => $suggest]])->toString(),
        ],
        'perms' => ['raise requests', 'receive goods'],
      ];
    }

    // 2. Price anomaly: latest PO vs 90-day average.
    foreach ($items as $item) {
      $hist = $this->repo->poHistory((int) $item['id'], $pids, 90);
      if (count($hist) < 3) {
        continue;
      }
      $last = array_pop($hist);
      $avg = array_sum(array_map(fn($h) => (float) $h['po_rate'], $hist)) / count($hist);
      $pct = ((float) $last['po_rate'] - $avg) / $avg * 100;
      if ($pct < 5) {
        continue;
      }
      $vendor = $this->repo->vendor((int) $last['vendor_id']);
      $extra = ((float) $last['po_rate'] - $avg) * (float) $last['qty'];
      $out[] = [
        'type' => 'price',
        'tag' => 'Price',
        'severity' => $pct >= 10 ? 'critical' : 'warning',
        'icon' => 'trend',
        'title' => $this->shortItem($item['name']) . ' cost ' . round($pct) . '% more than usual',
        'text' => ($vendor['name'] ?? 'The supplier') . ' charged ' . Format::inr($last['po_rate']) . ' per ' . Format::per($item['unit']) . '; we usually pay ' . Format::inr($avg) . '. Ask for a better price next time.',
        'action' => [
          'label' => 'Open request',
          'url' => Url::fromRoute('sthira.request', ['id' => $last['id']])->toString(),
        ],
        'perms' => ['issue purchase orders', 'view finance'],
      ];
    }

    // 3. Budget burn by category.
    foreach ($projects as $p) {
      $budgets = $this->repo->budgets((int) $p['id']);
      $committed = $this->repo->committedByCategory((int) $p['id']);
      foreach ($budgets as $cat => $amount) {
        $used = $committed[$cat] ?? 0;
        $pct = Format::pct($used, $amount);
        if ($pct < 85) {
          continue;
        }
        $out[] = [
          'type' => 'budget',
          'tag' => 'Budget',
          'severity' => $pct >= 100 ? 'critical' : 'warning',
          'icon' => 'rupee',
          'title' => $cat . ' budget ' . $pct . '% used at ' . $this->shortProject($p['name']),
          'text' => Format::short($used) . ' of ' . Format::short($amount) . ' is spent, and the project is ' . $p['progress'] . '% done. Check before buying more ' . strtolower($cat) . '.',
          'action' => [
            'label' => 'View project',
            'url' => Url::fromRoute('sthira.project', ['id' => $p['id']])->toString(),
          ],
          'perms' => ['view finance'],
        ];
      }
    }

    // 4. Approvals waiting too long.
    foreach ($this->repo->requests($pids, ['pm_review', 'director_review']) as $r) {
      $hours = (int) floor((time() - (int) $r['updated']) / 3600);
      if ($hours < 24) {
        continue;
      }
      $item = $items[$r['item_id']];
      $who = $r['stage'] === 'pm_review' ? 'PM' : 'Director';
      $out[] = [
        'type' => 'approval',
        'tag' => 'Approvals',
        'severity' => $hours >= 48 ? 'warning' : 'info',
        'icon' => 'clock',
        'title' => $this->shortItem($item['name']) . ' request waiting ' . $hours . ' hours for ' . $who . ' approval',
        'text' => Format::qty($r['qty']) . ' ' . $r['unit'] . ' for ' . $this->shortProject($projects[$r['project_id']]['name']) . ', needed by ' . Format::day($r['needed_by']) . '. Please review it.',
        'action' => [
          'label' => 'Review',
          'url' => Url::fromRoute('sthira.request', ['id' => $r['id']])->toString(),
        ],
        'perms' => [$r['stage'] === 'pm_review' ? 'approve requests' : 'approve high value requests'],
      ];
    }

    // 5. Invoice mismatches.
    foreach ($this->repo->requests($pids, ['invoiced']) as $r) {
      if ($r['match_status'] !== 'mismatch') {
        continue;
      }
      $m = $this->workflow->match($r);
      $vendor = $this->repo->vendor((int) $r['vendor_id']);
      $out[] = [
        'type' => 'invoice',
        'tag' => 'Invoice',
        'severity' => 'critical',
        'icon' => 'file',
        'title' => 'Bill from ' . ($vendor['name'] ?? 'a supplier') . ' does not match the delivery',
        'text' => 'They billed ' . Format::qty($r['invoice_qty']) . ' ' . $r['unit'] . ' but only ' . Format::qty($r['received_qty']) . ' ' . $r['unit'] . ' arrived. Pay only for what arrived and save ' . $m['difference'] . '.',
        'action' => [
          'label' => 'Resolve',
          'url' => Url::fromRoute('sthira.request', ['id' => $r['id']])->toString(),
        ],
        'perms' => ['process invoices'],
      ];
    }

    // 6. Rate contracts expiring.
    foreach ($this->repo->vendorsByStatus('approved') as $v) {
      if (!$v['contract_expiry']) {
        continue;
      }
      $days = (int) floor((strtotime($v['contract_expiry']) - strtotime('today')) / 86400);
      if ($days < 0 || $days > 30) {
        continue;
      }
      $spend = $this->repo->vendorSpend((int) $v['id'], 90);
      $out[] = [
        'type' => 'contract',
        'tag' => 'Contract',
        'severity' => $days <= 14 ? 'warning' : 'info',
        'icon' => 'calendar',
        'title' => 'Price agreement with ' . $v['name'] . ' ends in ' . $days . ' days',
        'text' => 'It ends on ' . Format::day($v['contract_expiry']) . '. Talk to them now to keep the same prices.',
        'action' => [
          'label' => 'Suppliers',
          'url' => Url::fromRoute('sthira.vendors')->toString(),
        ],
        'perms' => ['issue purchase orders', 'view vendors'],
      ];
    }

    // 7. Vendor registrations waiting for verification.
    $pending = $this->repo->vendorsByStatus('pending');
    if ($pending) {
      $oldest = min(array_column($pending, 'created'));
      $out[] = [
        'type' => 'vendors',
        'tag' => 'Vendors',
        'severity' => (time() - $oldest) > 2 * 86400 ? 'warning' : 'info',
        'icon' => 'users',
        'title' => count($pending) . (count($pending) === 1 ? ' new supplier is' : ' new suppliers are') . ' waiting for approval',
        'text' => implode(', ', array_column($pending, 'name')) . ' signed up and uploaded documents. Check and approve them.',
        'action' => [
          'label' => 'Check suppliers',
          'url' => Url::fromRoute('sthira.vendors', [], ['query' => ['view' => 'pending']])->toString(),
        ],
        'perms' => ['approve vendors'],
      ];
    }

    // Audience: directors and admins see everything; others see what concerns them.
    $seeAll = $a->hasPermission('approve high value requests') || $a->hasPermission('manage sthira settings');
    if (!$seeAll) {
      $out = array_values(array_filter($out, function ($i) use ($a) {
        foreach ($i['perms'] as $perm) {
          if ($a->hasPermission($perm)) {
            return TRUE;
          }
        }
        return FALSE;
      }));
    }
    usort($out, fn($x, $y) => self::SEVERITY_ORDER[$x['severity']] <=> self::SEVERITY_ORDER[$y['severity']]);
    return $out;
  }

  /**
   * Data for the conversational request box (parsed in the browser).
   */
  public function intakeSettings(AccountInterface $a): array {
    $pids = $this->repo->accessibleProjectIds($a);
    $finance = $a->hasPermission('view finance');
    $items = [];
    foreach ($this->repo->items() as $i) {
      $items[] = [
        'id' => (int) $i['id'],
        'name' => $i['name'],
        'short' => $this->shortItem($i['name']),
        'category' => $i['category'],
        'unit' => $i['unit'],
        'rate' => (float) $i['std_rate'],
        'keywords' => array_values(array_filter(array_map('trim', explode(',', $i['keywords'])))),
      ];
    }
    $pmLimit = $this->workflow->pmLimit();
    $directors = array_map(fn($uid) => $this->repo->person($uid)['name'], $this->repo->usersWithRole('director'));
    $pms = $this->repo->usersWithRole('project_manager');
    $projects = [];
    foreach ($this->repo->projects($pids) as $p) {
      $words = preg_split('/[^a-z0-9]+/', strtolower($p['name'] . ' ' . $p['location'] . ' ' . $p['client']));
      $keywords = array_values(array_unique(array_filter($words, fn($w) => strlen($w) >= 4 && !in_array($w, ['tower', 'park', 'city', 'trust', 'developers'], TRUE))));
      $keywords[] = strtolower($p['code']);
      $remaining = [];
      if ($finance) {
        $committed = $this->repo->committedByCategory((int) $p['id']);
        foreach ($this->repo->budgets((int) $p['id']) as $cat => $amount) {
          $remaining[$cat] = max(0, $amount - ($committed[$cat] ?? 0));
        }
      }
      $stock = [];
      foreach ($this->repo->stock((int) $p['id']) as $s) {
        if ((float) $s['daily_use'] > 0) {
          $stock[$s['item_id']] = round((float) $s['qty'] / (float) $s['daily_use'], 1);
        }
      }
      $pm = array_values(array_intersect($pms, $this->repo->members((int) $p['id'])));
      $projects[] = [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'short' => $this->shortProject($p['name']),
        'keywords' => $keywords,
        'remaining' => $remaining,
        'stockDays' => $stock,
        'pm' => $pm ? $this->repo->person($pm[0])['name'] : 'Project Manager',
      ];
    }
    return [
      'items' => $items,
      'projects' => $projects,
      'pmLimit' => $pmLimit,
      'pmLimitLabel' => Format::inr($pmLimit),
      'director' => $directors ? $directors[0] : 'Director',
      'finance' => $finance,
      'today' => date('Y-m-d'),
    ];
  }

  public function shortItem(string $name): string {
    $map = [
      'OPC 53 Grade Cement' => 'Cement',
      'TMT Steel Bars Fe500D' => 'TMT steel',
    ];
    return $map[$name] ?? $name;
  }

  public function shortProject(string $name): string {
    return trim(explode('–', $name)[0]);
  }

}
