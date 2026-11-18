<?php

namespace Drupal\sthira;

use Drupal\Core\Url;

/**
 * Numbers and chart data for the Director's overview dashboard.
 *
 * Everything is computed from live requests, projects and suppliers. Charts
 * are drawn in plain HTML and CSS by the template; this class only works out
 * values, shares (0-100) and labels, so the template stays simple.
 */
class Overview {

  public function __construct(
    protected Repository $repo,
    protected Workflow $workflow,
    protected Assistant $assistant,
  ) {}

  public function build(): array {
    $projects = $this->repo->projects();
    $pids = array_map('intval', array_keys($projects));
    $items = $this->repo->items();
    $vendors = $this->repo->vendors();
    $all = $this->repo->requests($pids);
    $ordered = array_filter($all, fn($r) => in_array($r['stage'], Repository::COMMITTED_STAGES, TRUE));

    // Headline numbers.
    $committed = array_sum(array_map(fn($r) => (float) $r['po_amount'], $ordered));
    $budget = array_sum(array_map(fn($pid) => $this->repo->budgetTotal($pid), $pids));
    $estimate = array_sum(array_map(fn($r) => (float) $r['est_amount'], $ordered));
    $saved = $estimate - $committed;
    $paid = array_filter($all, fn($r) => $r['stage'] === 'paid');
    $toPay = array_filter($all, fn($r) => in_array($r['stage'], ['received', 'invoiced'], TRUE));
    $cycle = array_values(array_filter(array_map(fn($r) => !empty($r['po_date']) ? ((int) $r['po_date'] - (int) $r['created']) / 86400 : NULL, $all), fn($x) => $x !== NULL));
    $bidsPer = [];
    foreach ($ordered as $r) {
      $bidsPer[] = count($this->repo->bids((int) $r['id']));
    }
    $usedPct = $budget > 0 ? $committed / $budget * 100 : 0;

    $hero = [
      'label' => 'Ordered so far',
      'value' => Format::short($committed),
      'sub' => 'of ' . Format::short($budget) . ' material budget across ' . count($pids) . ' projects',
      'pct' => round($usedPct),
      'meter' => min(100, $usedPct),
    ];
    $tiles = [
      [
        'label' => 'Saved through bidding',
        'value' => Format::short(max(0, $saved)),
        'sub' => ($estimate > 0 ? round($saved / $estimate * 100, 1) : 0) . '% below our estimates',
        'tone' => $saved >= 0 ? 'ok' : 'bad',
      ],
      [
        'label' => 'Paid to suppliers',
        'value' => Format::short(array_sum(array_column($paid, 'paid_amount'))),
        'sub' => count($paid) . ' payments made',
        'tone' => 'neutral',
      ],
      [
        'label' => 'Waiting to be paid',
        'value' => Format::short(array_sum(array_map(fn($r) => (float) ($r['invoice_amount'] ?: $r['po_amount']), $toPay))),
        'sub' => count($toPay) . ' delivered, bill not paid yet',
        'tone' => 'neutral',
      ],
      [
        'label' => 'Request to order',
        'value' => $cycle ? round(array_sum($cycle) / count($cycle), 1) . ' days' : '–',
        'sub' => 'average, with ' . ($bidsPer ? round(array_sum($bidsPer) / count($bidsPer), 1) : 0) . ' bids per order',
        'tone' => 'neutral',
      ],
    ];

    return [
      'hero' => $hero,
      'tiles' => $tiles,
      'months' => $this->months($ordered),
      'projects' => $this->projectBudgets($projects),
      'categories' => $this->categories($ordered, $items),
      'pipeline' => $this->pipeline($all),
      'suppliers' => $this->suppliers($ordered, $vendors),
      'as_of' => date('j M Y, g:i a'),
    ];
  }

  /**
   * Orders placed in each of the last six months.
   */
  protected function months(array $ordered): array {
    $buckets = [];
    for ($i = 5; $i >= 0; $i--) {
      $start = strtotime(date('Y-m-01', strtotime("first day of -$i month")));
      $buckets[date('Y-m', $start)] = ['label' => date('M', $start), 'long' => date('F Y', $start), 'value' => 0.0, 'count' => 0];
    }
    foreach ($ordered as $r) {
      $key = !empty($r['po_date']) ? date('Y-m', (int) $r['po_date']) : NULL;
      if ($key && isset($buckets[$key])) {
        $buckets[$key]['value'] += (float) $r['po_amount'];
        $buckets[$key]['count']++;
      }
    }
    $rows = array_values($buckets);
    $top = max(array_column($rows, 'value') ?: [0]);
    $max = $this->niceMax($top);
    foreach ($rows as $i => &$b) {
      $b['h'] = $max > 0 ? round($b['value'] / $max * 100, 2) : 0;
      $b['amount'] = Format::short($b['value']);
      $b['full'] = Format::inr($b['value']);
      $b['current'] = $i === count($rows) - 1;
      $b['peak'] = $top > 0 && $b['value'] === $top;
    }
    unset($b);
    // Three gridlines: 0, half, top.
    $ticks = [['at' => 100, 'label' => Format::short($max)], ['at' => 50, 'label' => Format::short($max / 2)], ['at' => 0, 'label' => '0']];
    $total = array_sum(array_column($rows, 'value'));
    return ['rows' => $rows, 'ticks' => $ticks, 'total' => Format::short($total), 'count' => array_sum(array_column($rows, 'count'))];
  }

  /**
   * Budget used per project.
   */
  protected function projectBudgets(array $projects): array {
    $out = [];
    foreach ($projects as $p) {
      $budget = $this->repo->budgetTotal((int) $p['id']);
      $spent = array_sum($this->repo->committedByCategory((int) $p['id']));
      $pct = $budget > 0 ? $spent / $budget * 100 : 0;
      $out[] = [
        'name' => $p['name'],
        'city' => $p['location'],
        'progress' => (int) $p['progress'],
        'spent' => Format::short($spent),
        'budget' => Format::short($budget),
        'pct' => round($pct),
        'fill' => min(100, $pct),
        'state' => $pct >= 100 ? 'critical' : ($pct >= 85 ? 'warning' : 'good'),
        'url' => Url::fromRoute('sthira.project', ['id' => $p['id']])->toString(),
      ];
    }
    return $out;
  }

  /**
   * Money ordered per material type, largest first.
   */
  protected function categories(array $ordered, array $items): array {
    $sum = [];
    $count = [];
    foreach ($ordered as $r) {
      $cat = $items[$r['item_id']]['category'] ?? 'Other';
      $cat = Repository::CATEGORIES[$cat] ?? $cat;
      $sum[$cat] = ($sum[$cat] ?? 0) + (float) $r['po_amount'];
      $count[$cat] = ($count[$cat] ?? 0) + 1;
    }
    arsort($sum);
    $total = array_sum($sum) ?: 1;
    $max = max($sum ?: [1]);
    $out = [];
    foreach ($sum as $cat => $v) {
      $out[] = [
        'label' => $cat,
        'w' => round($v / $max * 100, 2),
        'amount' => Format::short($v),
        'full' => Format::inr($v),
        'share' => round($v / $total * 100),
        'count' => $count[$cat],
      ];
    }
    return $out;
  }

  /**
   * How many requests sit at each step right now.
   */
  protected function pipeline(array $all): array {
    $steps = [
      'pm_review' => 'Waiting for PM approval',
      'approved' => 'Ready for bids',
      'bidding' => 'Collecting bids',
      'order_review' => 'Order approval',
      'ordered' => 'Waiting for delivery',
      'received' => 'Bill to check',
      'invoiced' => 'Ready to pay',
    ];
    $rows = [];
    foreach ($steps as $stage => $label) {
      $in = array_filter($all, fn($r) => $r['stage'] === $stage);
      $value = array_sum(array_map(fn($r) => (float) ($r['po_amount'] ?: $r['est_amount']), $in));
      $rows[] = ['label' => $label, 'count' => count($in), 'amount' => Format::short($value), 'full' => Format::inr($value)];
    }
    $monthAgo = time() - 30 * 86400;
    $done = array_filter($all, fn($r) => $r['stage'] === 'paid' && (int) $r['paid_date'] >= $monthAgo);
    $max = max(array_column($rows, 'count') ?: [1]) ?: 1;
    foreach ($rows as &$row) {
      $row['w'] = round($row['count'] / $max * 100, 2);
    }
    unset($row);
    return [
      'rows' => $rows,
      'open' => array_sum(array_column($rows, 'count')),
      'done' => count($done),
    ];
  }

  /**
   * Suppliers by order value, with their delivery record.
   */
  protected function suppliers(array $ordered, array $vendors): array {
    $sum = [];
    $count = [];
    foreach ($ordered as $r) {
      $vid = (int) $r['vendor_id'];
      $sum[$vid] = ($sum[$vid] ?? 0) + (float) $r['po_amount'];
      $count[$vid] = ($count[$vid] ?? 0) + 1;
    }
    arsort($sum);
    $sum = array_slice($sum, 0, 6, TRUE);
    $max = max($sum ?: [1]);
    $out = [];
    foreach ($sum as $vid => $v) {
      $vendor = $vendors[$vid] ?? ['name' => 'Supplier', 'on_time' => 0, 'rating' => 0, 'categories' => ''];
      $out[] = [
        'name' => $vendor['name'],
        'what' => $vendor['categories'],
        'w' => round($v / $max * 100, 2),
        'amount' => Format::short($v),
        'full' => Format::inr($v),
        'orders' => $count[$vid],
        'on_time' => (int) $vendor['on_time'],
        'on_time_state' => $vendor['on_time'] >= 85 ? 'good' : ($vendor['on_time'] >= 75 ? 'warning' : 'critical'),
        'rating' => number_format((float) $vendor['rating'], 1),
      ];
    }
    return $out;
  }

  /**
   * Rounds a chart maximum up to a clean number (1, 2, 2.5 or 5 × 10^n).
   */
  protected function niceMax(float $v): float {
    if ($v <= 0) {
      return 0;
    }
    $exp = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
      if ($m * $exp >= $v) {
        return $m * $exp;
      }
    }
    return 10 * $exp;
  }

}
