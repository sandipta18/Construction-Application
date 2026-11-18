<?php

namespace Drupal\sthira\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sthira\Assistant;
use Drupal\sthira\Form\LimitsForm;
use Drupal\sthira\Form\StageForm;
use Drupal\sthira\Form\VendorReviewForm;
use Drupal\sthira\Format;
use Drupal\sthira\Icons;
use Drupal\sthira\Photos;
use Drupal\sthira\Repository;
use Drupal\sthira\Workflow;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * All app pages.
 */
class AppController extends ControllerBase {

  public function __construct(
    protected Repository $repo,
    protected Workflow $workflow,
    protected Assistant $assistant,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sthira.repository'),
      $container->get('sthira.workflow'),
      $container->get('sthira.assistant'),
    );
  }

  /* ---------------------------------------------------------------------
   * Entry point and role switcher.
   * ------------------------------------------------------------------- */

  /**
   * Public homepage for suppliers: what we're buying, our projects, search.
   *
   * Shows quantities, places and dates only. Prices and budgets stay private.
   */
  public function home() {
    $a = $this->currentUser();
    $items = $this->repo->items();
    $projects = $this->repo->projects();
    $stageLabel = ['bidding' => 'Open for bids', 'approved' => 'Opening soon', 'pm_review' => 'Coming soon'];

    $requirements = [];
    foreach ($this->repo->requests(NULL, ['bidding', 'approved', 'pm_review']) as $r) {
      if (!empty($r['bids_closed'])) {
        continue;
      }
      $item = $items[$r['item_id']] ?? NULL;
      $p = $projects[$r['project_id']] ?? NULL;
      if (!$item || !$p) {
        continue;
      }
      $requirements[] = [
        'ref' => $r['ref'],
        'title' => Format::qty($r['qty']) . ' ' . $r['unit'] . ' · ' . $item['name'],
        'material' => $item['name'],
        'material_id' => $item['id'],
        'category' => $item['category'],
        'qty' => Format::qty($r['qty']) . ' ' . $r['unit'],
        'project' => $this->assistant->shortProject($p['name']),
        'city' => $p['location'],
        'needed' => Format::day($r['needed_by']),
        'needed_iso' => $r['needed_by'],
        'status' => $stageLabel[$r['stage']],
        'live' => $r['stage'] === 'bidding',
        'urgent' => $r['priority'] === 'urgent',
      ];
    }
    usort($requirements, fn($x, $y) => $y['live'] <=> $x['live']);

    $projectCards = [];
    foreach ($projects as $p) {
      $projectCards[] = [
        'name' => $p['name'],
        'code' => $p['code'],
        'city' => $p['location'],
        'client' => $p['client'],
        'phase' => $p['phase'],
        'progress' => $p['progress'],
        'photo' => $p['photo'] ?: Photos::remote($p['code']),
        'until' => date('M Y', strtotime($p['end_date'])),
        'buying' => count(array_filter($requirements, fn($q) => $q['project'] === $this->assistant->shortProject($p['name']))),
      ];
    }

    // Always build fresh: the page shows live requirements, and Drupal's
    // anonymous page cache ignores max-age 0.
    \Drupal::service('page_cache_kill_switch')->trigger();
    Photos::healLater($projects);

    // Banner slides: one per project, with what it needs, in a sentence.
    $slides = [];
    foreach ($projects as $p) {
      $short = $this->assistant->shortProject($p['name']);
      $needs = array_values(array_filter($requirements, fn($q) => $q['project'] === $short));
      $parts = array_map(fn($q) => $q['qty'] . ' of ' . $this->assistant->shortItem($q['material']), array_slice($needs, 0, 2));
      if ($needs) {
        $soonest = min(array_map(fn($q) => strtotime($q['needed_iso']), $needs));
        $sentence = $short . ' needs ' . implode(' and ', $parts) . (count($needs) > 2 ? ', plus ' . (count($needs) - 2) . ' more' : '') . ', first by ' . Format::day(date('Y-m-d', $soonest)) . '.';
      }
      else {
        $sentence = $short . ' has no open requirement today. Register to hear first when it does.';
      }
      $slides[] = [
        'name' => $p['name'],
        'city' => $p['location'],
        'phase' => $p['phase'],
        'progress' => $p['progress'],
        'photo' => $p['photo'] ?: Photos::remote($p['code']),
        'sentence' => $sentence,
        'open' => count($needs),
      ];
    }

    $categories = [];
    foreach (Repository::CATEGORIES as $key => $label) {
      $categories[] = [
        'label' => $label,
        'items' => array_values(array_map(fn($i) => $i['name'], array_filter($items, fn($i) => $i['category'] === $label))),
        'open' => count(array_filter($requirements, fn($q) => $q['category'] === $label)),
      ];
    }

    $search = [
      'endpoint' => Url::fromRoute('sthira.search_api')->toString(),
      'register' => Url::fromRoute('sthira.vendor_register')->toString(),
    ];

    $signedIn = $a->isAuthenticated();
    return [
      '#theme' => 'sthira_landing',
      '#requirements' => array_slice($requirements, 0, 6),
      '#slides' => $slides,
      '#requirement_count' => count($requirements),
      '#projects' => $projectCards,
      '#categories' => $categories,
      '#stats' => [
        ['value' => count($projects), 'label' => 'Active project sites'],
        ['value' => count($this->repo->vendorsByStatus('approved')), 'label' => 'Verified suppliers'],
        ['value' => count(Repository::CATEGORIES), 'label' => 'Material categories we buy'],
        ['value' => '30', 'label' => 'Days to payment after a matched invoice'],
      ],
      '#suggestions' => ['active projects', 'rebar near Mumbai', 'what is needed urgently?', 'hospital project', 'what is M-Sand?', 'how do I get paid?'],
      '#urls' => [
        'register' => Url::fromRoute('sthira.vendor_register')->toString(),
        'login' => Url::fromRoute('user.login')->toString(),
        'app' => $signedIn ? Url::fromRoute($a->hasPermission('access sthira app') ? 'sthira.dashboard' : ($a->hasPermission('access vendor portal') ? 'sthira.vendor_portal' : 'user.page'))->toString() : NULL,
      ],
      '#attached' => [
        'library' => ['sthira/landing', 'sthira_theme/landing'],
        'drupalSettings' => ['sthiraSearch' => $search],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Settings: one page linking to the less-used admin pages.
   */
  public function settings() {
    $a = $this->currentUser();
    $icons = Icons::all();
    $cards = [
      ['Assistant tips', 'Every tip about prices, budgets, stock and waiting requests.', 'sthira.insights', 'sparkle', TRUE],
      ['Edit content', 'Projects, materials, suppliers, prices and supplier FAQ.', 'system.admin_content', 'file', $a->hasPermission('access content overview')],
      ['Roles & access', 'Who can do what, and the approval limit for project managers.', 'sthira.access', 'shield', TRUE],
      ['History', 'Everything that happened, step by step.', 'sthira.audit', 'clock', $a->hasPermission('view audit log')],
      ['Homepage search', 'What the public search knows, and a place to test it.', 'sthira.search_admin', 'sparkle', $a->hasPermission('manage search index')],
    ];
    $out = [];
    foreach ($cards as [$title, $text, $route, $icon, $show]) {
      if ($show) {
        $out[] = ['title' => $title, 'text' => $text, 'url' => Url::fromRoute($route)->toString(), 'icon' => $icons[$icon]];
      }
    }
    return ['#theme' => 'sthira_settings', '#cards' => $out, '#cache' => ['max-age' => 0]];
  }

  public function switchUser(int $uid, Request $request) {
    $demo = $this->repo->demoUids();
    if (!$this->workflow->demoMode() || !in_array((int) $this->currentUser()->id(), $demo, TRUE) || !in_array($uid, $demo, TRUE)) {
      throw new AccessDeniedHttpException();
    }
    $account = User::load($uid);
    if (!$account) {
      throw new NotFoundHttpException();
    }
    user_login_finalize($account);
    $p = $this->repo->person($uid);
    $this->messenger()->addStatus($this->t('You are now viewing as @name (@role).', ['@name' => $p['name'], '@role' => $p['title']]));

    $back = (string) $request->query->get('back', '');
    if ($back && str_starts_with($back, '/') && !str_starts_with($back, '//')) {
      try {
        $url = Url::fromUserInput(parse_url($back, PHP_URL_PATH));
        if ($url->isRouted() && $url->access($account) && !in_array($url->getRouteName(), ['sthira.switch'], TRUE)) {
          // Pages that need a project the new person cannot see fall back.
          $params = $url->getRouteParameters();
          $home = $account->hasPermission('access sthira app') ? 'sthira.dashboard' : 'sthira.vendor_portal';
          if ($url->getRouteName() === 'sthira.request' && !$this->canSeeRequest((int) $params['id'], $account)) {
            return $this->redirect($home);
          }
          if ($url->getRouteName() === 'sthira.project' && !in_array((int) $params['id'], $this->repo->accessibleProjectIds($account), TRUE)) {
            return $this->redirect($home);
          }
          return new RedirectResponse($url->toString());
        }
      }
      catch (\Exception $e) {
        // Fall through to the dashboard.
      }
    }
    return $this->redirect($account->hasPermission('access sthira app') ? 'sthira.dashboard' : 'sthira.vendor_portal');
  }

  /* ---------------------------------------------------------------------
   * Dashboard.
   * ------------------------------------------------------------------- */

  public function dashboard() {
    $a = $this->currentUser();
    $pids = $this->repo->accessibleProjectIds($a);
    $me = $this->repo->person((int) $a->id());
    $finance = $a->hasPermission('view finance');
    $queue = $this->workflow->queue($a);
    $insights = $this->assistant->insights($a);
    $role = $this->roleKey($a);

    $hour = (int) date('G');
    $greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $first = explode(' ', $me['name'])[0];
    $subtitle = count($queue) > 0
      ? 'You have ' . count($queue) . ' thing' . (count($queue) === 1 ? '' : 's') . ' to do. Click one to start.'
      : 'You\'re all caught up.';

    return [
      '#theme' => 'sthira_dashboard',
      '#add_links' => $this->addLinks(['project', 'material', 'vendor', 'vendor_rate']),
      '#content_url' => $a->hasPermission('access content overview') ? Url::fromRoute('system.admin_content')->toString() : NULL,
      '#greeting' => $greet . ', ' . $first,
      '#subtitle' => $subtitle,
      '#role' => $me['title'],
      '#kpis' => $this->kpis($role, $a, $pids, $queue, $insights),
      '#queue' => array_slice($this->rows($queue, $a), 0, 5),
      '#queue_count' => count($queue),
      '#insights' => array_slice($this->insightRows($insights), 0, 3),
      '#insight_count' => count($insights),
      '#can_raise' => $a->hasPermission('raise requests'),
      '#urls' => $this->urls(),
      '#cache' => ['max-age' => 0],
    ];
  }

  protected function kpis(string $role, $a, array $pids, array $queue, array $insights): array {
    $all = $this->repo->requests($pids);
    $open = array_filter($all, fn($r) => in_array($r['stage'], Repository::OPEN_STAGES, TRUE));
    $inApproval = array_filter($all, fn($r) => in_array($r['stage'], ['pm_review', 'director_review'], TRUE));
    $monthStart = strtotime(date('Y-m-01'));
    $ordered = array_filter($all, fn($r) => !empty($r['po_amount']));
    $savings = array_sum(array_map(fn($r) => (float) $r['est_amount'] - (float) $r['po_amount'], $ordered));
    $budget = array_sum(array_map(fn($pid) => $this->repo->budgetTotal($pid), $pids));
    $committed = $this->repo->committedTotal($pids);
    $mismatch = array_filter($all, fn($r) => $r['stage'] === 'invoiced' && $r['match_status'] === 'mismatch');
    $paidMonth = array_filter($all, fn($r) => $r['stage'] === 'paid' && (int) $r['paid_date'] >= $monthStart);
    $posMonth = array_filter($all, fn($r) => !empty($r['po_date']) && (int) $r['po_date'] >= $monthStart);
    $mine = array_filter($open, fn($r) => (int) $r['created_by'] === (int) $a->id());
    $receivedWeek = array_filter($all, fn($r) => !empty($r['grn_date']) && (int) $r['grn_date'] >= time() - 7 * 86400);
    $critical = count(array_filter($insights, fn($i) => $i['severity'] === 'critical'));

    $defs = [
      'queue' => ['To do', count($queue), (string) count($queue), 'waiting for you', 'accent', 'bell'],
      'committed' => ['Spent so far', NULL, Format::short($committed), 'of ' . Format::short($budget) . ' budget', 'neutral', 'rupee'],
      'approval_value' => ['Waiting for approval', NULL, Format::short(array_sum(array_column($inApproval, 'est_amount'))), count($inApproval) . ' request' . (count($inApproval) === 1 ? '' : 's'), 'neutral', 'clock'],
      'savings' => ['Saved', NULL, Format::short($savings), 'compared with estimates', $savings >= 0 ? 'ok' : 'bad', 'trend'],
      'alerts' => ['AI flags', count($insights), (string) count($insights), $critical . ' critical · see Insights', 'violet', 'sparkle'],
      'open' => ['In progress', count($open), (string) count($open), 'requests not finished yet', 'neutral', 'requests'],
      'mine' => ['My open requests', count($mine), (string) count($mine), 'raised by you, not yet delivered', 'accent', 'requests'],
      'deliveries' => ['Deliveries due', count(array_filter($all, fn($r) => $r['stage'] === 'ordered')), (string) count(array_filter($all, fn($r) => $r['stage'] === 'ordered')), 'POs issued, awaiting site receipt', 'neutral', 'truck'],
      'received_week' => ['Received this week', count($receivedWeek), (string) count($receivedWeek), 'deliveries recorded at site', 'ok', 'box'],
      'mismatch' => ['Bills that don\'t match', count($mismatch), (string) count($mismatch), count($mismatch) ? 'need a look' : 'all good', count($mismatch) ? 'bad' : 'ok', 'file'],
      'paid_month' => ['Paid this month', NULL, Format::short(array_sum(array_column($paidMonth, 'paid_amount'))), count($paidMonth) . ' vendor payments', 'neutral', 'rupee'],
      'pos_month' => ['Orders this month', count($posMonth), (string) count($posMonth), Format::short(array_sum(array_column($posMonth, 'po_amount'))) . ' in total', 'neutral', 'file'],
      'projects' => ['Projects', count($pids), (string) count($pids), 'you are working on', 'neutral', 'projects'],
    ];
    $layout = [
      'director' => ['queue', 'committed', 'savings'],
      'project_manager' => ['queue', 'open', 'committed'],
      'procurement_officer' => ['queue', 'pos_month', 'mismatch'],
      'admin' => ['open', 'committed', 'savings'],
    ];
    $icons = Icons::all();
    $out = [];
    foreach ($layout[$role] ?? $layout['admin'] as $key) {
      [$label, $count, $value, $sub, $tone, $icon] = $defs[$key];
      $out[] = ['label' => $label, 'count' => $count, 'value' => $value, 'sub' => $sub, 'tone' => $tone, 'icon' => $icons[$icon]];
    }
    return $out;
  }

  /* ---------------------------------------------------------------------
   * Requests.
   * ------------------------------------------------------------------- */

  public function requests(Request $request) {
    $a = $this->currentUser();
    $pids = $this->repo->accessibleProjectIds($a);
    $queue = $this->workflow->queue($a);
    $tabs = [
      'queue' => ['Waiting on me', NULL],
      'open' => ['In progress', ['pm_review', 'approved', 'bidding', 'director_review', 'ordered', 'received', 'invoiced']],
      'closed' => ['Closed', ['paid', 'rejected']],
    ];
    if (!$this->workflow->queueStages($a)) {
      unset($tabs['queue']);
    }
    $view = (string) $request->query->get('view', '');
    if (!isset($tabs[$view])) {
      $view = isset($tabs['queue']) && $queue ? 'queue' : 'open';
    }
    if ($view === 'queue') {
      $rows = $queue;
    }
    else {
      $rows = $this->repo->requests($pids, $tabs[$view][1]);
    }
    $tabOut = [];
    foreach ($tabs as $key => [$label, $stages]) {
      $count = $key === 'queue' ? count($queue) : count($this->repo->requests($pids, $stages));
      $tabOut[] = [
        'label' => $label,
        'count' => $count,
        'active' => $key === $view,
        'url' => Url::fromRoute('sthira.requests', [], ['query' => ['view' => $key]])->toString(),
      ];
    }
    return [
      '#theme' => 'sthira_requests',
      '#tabs' => $tabOut,
      '#rows' => $this->rows($rows, $a),
      '#can_raise' => $a->hasPermission('raise requests'),
      '#finance' => $a->hasPermission('view finance'),
      '#empty' => $view === 'queue' ? 'Nothing is waiting on you.' : 'No requests here yet.',
      '#urls' => $this->urls(),
      '#cache' => ['max-age' => 0],
    ];
  }

  public function request(int $id) {
    $a = $this->currentUser();
    $r = $this->repo->request($id);
    if (!$r || !$this->canSeeRequest($id, $a)) {
      throw new NotFoundHttpException();
    }
    $item = $this->repo->item((int) $r['item_id']);
    $project = $this->repo->project((int) $r['project_id']);
    $finance = $a->hasPermission('view finance') || (int) $r['created_by'] === (int) $a->id();
    $stage = Workflow::stage($r['stage']);
    $logs = $this->repo->logs($id, 0);
    $canAct = $this->workflow->canAct($r, $a);
    $vendor = $r['vendor_id'] ? $this->repo->vendor((int) $r['vendor_id']) : NULL;
    $by = $this->repo->person((int) $r['created_by']);

    $budget = NULL;
    if ($a->hasPermission('view finance')) {
      $cat = $item['category'];
      $budgets = $this->repo->budgets((int) $r['project_id']);
      $committed = $this->repo->committedByCategory((int) $r['project_id']);
      $total = (float) ($budgets[$cat] ?? 0);
      $used = (float) ($committed[$cat] ?? 0);
      $isCommitted = in_array($r['stage'], Repository::COMMITTED_STAGES, TRUE);
      $after = $isCommitted ? $used : $used + (float) $r['est_amount'];
      $budget = [
        'category' => $cat,
        'total' => Format::short($total),
        'used' => Format::short($used),
        'used_pct' => min(100, Format::pct($used, $total)),
        'after_pct' => min(100, Format::pct($after, $total)),
        'after_label' => Format::pct($after, $total),
        'adds' => $isCommitted ? NULL : Format::short($r['est_amount']),
        'tone' => Format::pct($after, $total) >= 85 ? 'bad' : 'ok',
      ];
    }

    // Bids: ranked by the assistant; the winner is marked once chosen.
    $reco = [];
    $waitingBids = [];
    if ($a->hasPermission('view finance') && $r['invited']) {
      $bids = $this->repo->bids((int) $r['id']);
      $reco = $this->assistant->rankBids($r, $bids);
      foreach ($reco as &$b) {
        $b['status'] = $bids[$b['bid_id']]['status'] ?? 'submitted';
        $b['received'] = Format::ago((int) ($bids[$b['bid_id']]['changed'] ?? 0));
      }
      unset($b);
      $vendors = $this->repo->vendors();
      foreach (array_diff($r['invited'], array_column($bids, 'vendor_id')) as $vid) {
        $waitingBids[] = $vendors[$vid]['name'] ?? '';
      }
    }
    $match = NULL;
    if (!empty($r['invoice_no'])) {
      $match = $this->workflow->match($r);
    }

    $waiting = NULL;
    if (!$canAct) {
      $waiting = $this->workflow->waitingOn($r);
    }

    $view = [
      'ref' => $r['ref'],
      'title' => Format::qty($r['qty']) . ' ' . $r['unit'] . ' · ' . $item['name'],
      'item' => $item['name'],
      'category' => $item['category'],
      'qty' => Format::qty($r['qty']) . ' ' . $r['unit'],
      'project' => $project['name'],
      'project_code' => $project['code'],
      'project_url' => Url::fromRoute('sthira.project', ['id' => $project['id']])->toString(),
      'stage' => $stage['long'],
      'stage_key' => $r['stage'],
      'bids_open' => $r['stage'] === 'bidding' && empty($r['bids_closed']),
      'tone' => $stage['tone'],
      'by' => $by,
      'created' => Format::date((int) $r['created']) . ' · ' . Format::ago((int) $r['created']),
      'needed_by' => Format::day($r['needed_by']),
      'priority' => $r['priority'],
      'note' => $r['note'],
      'raw_text' => $r['raw_text'],
      'est_rate' => $finance ? Format::inr($r['est_rate']) . '/' . Format::per($r['unit']) : NULL,
      'est_amount' => $finance ? Format::inr($r['est_amount']) : NULL,
      'amount' => $finance ? Format::inr($r['po_amount'] ?: $r['est_amount']) : NULL,
      'amount_label' => $r['po_amount'] ? 'Order value' : 'Estimated cost',
      'needs_director' => $this->workflow->needsDirector($r),
      'po' => $r['po_number'] ? [
        'number' => $r['po_number'],
        'vendor' => $vendor['name'] ?? '',
        'vendor_city' => $vendor['city'] ?? '',
        'rate' => $finance ? Format::inr($r['po_rate']) . '/' . Format::per($r['unit']) : NULL,
        'amount' => $finance ? Format::inr($r['po_amount']) : NULL,
        'date' => Format::date((int) $r['po_date']),
        'received' => $r['received_qty'] !== NULL ? Format::qty($r['received_qty']) . ' ' . $r['unit'] : NULL,
        'grn_date' => Format::date((int) $r['grn_date']),
        'paid' => $r['paid_amount'] ? Format::inr($r['paid_amount']) : NULL,
      ] : NULL,
    ];

    return [
      '#theme' => 'sthira_request',
      '#r' => $view,
      '#steps' => $this->workflow->steps($r, $logs),
      '#action_form' => $canAct ? $this->formBuilder()->getForm(StageForm::class, $id) : NULL,
      '#waiting' => $waiting,
      '#reco' => $reco,
      '#waiting_bids' => $waitingBids,
      '#match' => $match,
      '#budget' => $budget,
      '#timeline' => $this->logRows($logs, !$finance),
      '#finance' => $finance,
      '#urls' => $this->urls() + ['edit' => $this->editUrl($id)],
      '#cache' => ['max-age' => 0],
    ];
  }

  /* ---------------------------------------------------------------------
   * Projects.
   * ------------------------------------------------------------------- */

  public function projects() {
    $a = $this->currentUser();
    $finance = $a->hasPermission('view finance');
    $out = [];
    foreach ($this->repo->projects($this->repo->accessibleProjectIds($a)) as $p) {
      $budget = $this->repo->budgetTotal((int) $p['id']);
      $committed = array_sum($this->repo->committedByCategory((int) $p['id']));
      $pct = Format::pct($committed, $budget);
      $out[] = [
        'name' => $p['name'],
        'code' => $p['code'],
        'location' => $p['location'],
        'client' => $p['client'],
        'phase' => $p['phase'],
        'progress' => (int) $p['progress'],
        'dates' => date('M Y', strtotime($p['start_date'])) . ' – ' . date('M Y', strtotime($p['end_date'])),
        'contract' => $finance ? Format::short($p['budget']) : NULL,
        'budget' => Format::short($budget),
        'committed' => Format::short($committed),
        'pct' => $pct,
        'tone' => $pct >= 85 ? 'bad' : ($pct >= 70 ? 'warn' : 'ok'),
        'open' => count($this->repo->requests([(int) $p['id']], Repository::OPEN_STAGES)),
        'team' => array_map(fn($uid) => $this->repo->person($uid), $this->repo->members((int) $p['id'])),
        'url' => Url::fromRoute('sthira.project', ['id' => $p['id']])->toString(),
      ];
    }
    return [
      '#theme' => 'sthira_projects',
      '#add_links' => $this->addLinks(['project', 'site_stock']),
      '#projects' => $out,
      '#finance' => $finance,
      '#cache' => ['max-age' => 0],
    ];
  }

  public function project(int $id) {
    $a = $this->currentUser();
    if (!in_array($id, $this->repo->accessibleProjectIds($a), TRUE)) {
      throw new NotFoundHttpException();
    }
    $p = $this->repo->project($id);
    $finance = $a->hasPermission('view finance');
    $items = $this->repo->items();

    $budgets = [];
    if ($finance) {
      $committed = $this->repo->committedByCategory($id);
      foreach ($this->repo->budgets($id) as $cat => $amount) {
        $used = $committed[$cat] ?? 0;
        $pct = Format::pct($used, $amount);
        $budgets[] = [
          'category' => $cat,
          'budget' => Format::short($amount),
          'used' => Format::short($used),
          'left' => Format::short(max(0, $amount - $used)),
          'pct' => $pct,
          'width' => min(100, $pct),
          'tone' => $pct >= 85 ? 'bad' : ($pct >= 70 ? 'warn' : 'ok'),
        ];
      }
    }
    $stock = [];
    foreach ($this->repo->stock($id) as $s) {
      $item = $items[$s['item_id']];
      $days = (float) $s['daily_use'] > 0 ? (float) $s['qty'] / (float) $s['daily_use'] : NULL;
      $open = $this->repo->hasOpenRequest($id, (int) $s['item_id']);
      $stock[] = [
        'item' => $item['name'],
        'qty' => Format::qty($s['qty']) . ' ' . $item['unit'],
        'use' => (float) $s['daily_use'] > 0 ? Format::qty($s['daily_use']) . ' ' . $item['unit'] . '/day' : '—',
        'days' => $days !== NULL ? round($days, 1) : NULL,
        'tone' => $days === NULL ? 'neutral' : ($days < 3 ? 'bad' : ($days < 5 ? 'warn' : 'ok')),
        'width' => $days === NULL ? 100 : min(100, (int) round($days / 14 * 100)),
        'open' => $open,
        'reorder' => $days !== NULL && $days < 5 && !$open && $a->hasPermission('raise requests')
          ? Url::fromRoute('sthira.request_new', [], ['query' => ['project' => $id, 'item' => $s['item_id'], 'qty' => (int) (ceil((float) $s['daily_use'] * 14 / 10) * 10)]])->toString()
          : NULL,
      ];
    }
    usort($stock, fn($x, $y) => ($x['days'] ?? 999) <=> ($y['days'] ?? 999));

    $team = [];
    foreach ($this->repo->members($id) as $uid) {
      $team[] = $this->repo->person($uid);
    }

    return [
      '#theme' => 'sthira_project',
      '#p' => [
        'name' => $p['name'],
        'code' => $p['code'],
        'location' => $p['location'],
        'client' => $p['client'],
        'phase' => $p['phase'],
        'progress' => (int) $p['progress'],
        'dates' => date('d M Y', strtotime($p['start_date'])) . ' – ' . date('d M Y', strtotime($p['end_date'])),
        'contract' => $finance ? Format::short($p['budget']) : NULL,
        'material_budget' => $finance ? Format::short($this->repo->budgetTotal($id)) : NULL,
        'committed' => $finance ? Format::short(array_sum($this->repo->committedByCategory($id))) : NULL,
      ],
      '#budgets' => $budgets,
      '#stock' => $stock,
      '#requests' => $this->rows($this->repo->requests([$id], NULL, 6), $a),
      '#team' => $team,
      '#finance' => $finance,
      '#urls' => $this->urls() + ['edit' => $this->editUrl($id)],
      '#cache' => ['max-age' => 0],
    ];
  }

  /* ---------------------------------------------------------------------
   * Vendors, insights, access, audit.
   * ------------------------------------------------------------------- */

  public function vendors(Request $request) {
    $a = $this->currentUser();
    $finance = $a->hasPermission('view finance');
    $canApprove = $a->hasPermission('approve vendors');
    $counts = [];
    foreach (['approved', 'pending', 'rejected'] as $st) {
      $counts[$st] = count($this->repo->vendorsByStatus($st));
    }
    $view = (string) $request->query->get('view', 'approved');
    if (!isset($counts[$view])) {
      $view = 'approved';
    }
    $tabs = [];
    foreach (['approved' => 'Approved', 'pending' => 'Waiting for approval', 'rejected' => 'Not approved'] as $key => $label) {
      $tabs[] = [
        'label' => $label,
        'count' => $counts[$key],
        'active' => $key === $view,
        'url' => Url::fromRoute('sthira.vendors', [], ['query' => ['view' => $key]])->toString(),
      ];
    }

    $out = [];
    foreach ($this->repo->vendorsByStatus($view) as $v) {
      $days = $v['contract_expiry'] ? (int) floor((strtotime($v['contract_expiry']) - strtotime('today')) / 86400) : NULL;
      $row = [
        'id' => $v['id'],
        'name' => $v['name'],
        'initials' => Format::initials($v['name']),
        'city' => $v['city'],
        'contact' => $v['contact'],
        'phone' => $v['phone'],
        'gstin' => $v['gstin'],
        'pan' => $v['pan'],
        'bank' => $v['bank'],
        'documents' => $v['documents'],
        'note' => $v['review_note'],
        'registered' => Format::ago($v['created']),
        'categories' => array_map('trim', explode(',', $v['categories'])),
        'rating' => number_format((float) $v['rating'], 1),
        'rating_width' => (int) round((float) $v['rating'] / 5 * 100),
        'on_time' => (int) $v['on_time'],
        'on_time_tone' => $v['on_time'] >= 85 ? 'ok' : ($v['on_time'] >= 75 ? 'warn' : 'bad'),
        'orders' => (int) $v['orders'],
        'spend' => $finance ? Format::short($this->repo->vendorSpend((int) $v['id'])) : NULL,
        'contract' => Format::day($v['contract_expiry']),
        'contract_days' => $days,
        'contract_tone' => $days !== NULL && $days <= 14 ? 'bad' : ($days !== NULL && $days <= 30 ? 'warn' : 'neutral'),
        'has_login' => (bool) $v['user_id'],
        'review_form' => NULL,
      ];
      if ($view === 'pending' && $canApprove) {
        $formObject = VendorReviewForm::create(\Drupal::getContainer())->setVendorId((int) $v['id']);
        $row['review_form'] = $this->formBuilder()->getForm($formObject, (int) $v['id']);
      }
      $out[] = $row;
    }
    usort($out, fn($x, $y) => $y['on_time'] <=> $x['on_time']);
    return [
      '#theme' => 'sthira_vendors',
      '#add_links' => $this->addLinks(['vendor', 'vendor_rate', 'material']),
      '#vendors' => $out,
      '#finance' => $finance,
      '#tabs' => $tabs,
      '#view' => $view,
      '#can_approve' => $canApprove,
      '#cache' => ['max-age' => 0],
    ];
  }

  public function insights() {
    return [
      '#theme' => 'sthira_insights',
      '#insights' => $this->insightRows($this->assistant->insights($this->currentUser())),
      '#cache' => ['max-age' => 0],
    ];
  }

  public function access() {
    $a = $this->currentUser();
    $columns = [
      'raise requests' => 'Raise',
      'approve requests' => 'Approve requests',
      'issue purchase orders' => 'Invite bids & order',
      'access vendor portal' => 'Bid',
      'approve high value requests' => 'Approve orders',
      'receive goods' => 'Confirm delivery',
      'process invoices' => 'Check bill & pay',
      'approve vendors' => 'Verify vendors',
      'view finance' => 'See ₹',
    ];
    $roleIds = ['director', 'project_manager', 'procurement_officer', 'vendor', 'administrator'];
    $roles = [];
    $storage = $this->entityTypeManager()->getStorage('user_role');
    foreach ($roleIds as $rid) {
      /** @var \Drupal\user\RoleInterface $role */
      $role = $storage->load($rid);
      if (!$role) {
        continue;
      }
      $cells = [];
      foreach (array_keys($columns) as $perm) {
        $cells[] = $role->isAdmin() || $role->hasPermission($perm);
      }
      $people = array_map(fn($uid) => $this->repo->person($uid), $this->repo->usersWithRole($rid));
      $scope = match ($rid) {
        'project_manager' => 'Own projects',
        'vendor' => 'Own company only',
        default => 'All projects',
      };
      $roles[] = [
        'label' => $role->label(),
        'cells' => $cells,
        'people' => $people,
        'scope' => $scope,
      ];
    }

    $projects = $this->repo->projects();
    $team = [];
    foreach ($this->repo->demoUids() as $uid) {
      $account = User::load($uid);
      if (!$account) {
        continue;
      }
      $p = $this->repo->person($uid);
      $codes = array_map(fn($pid) => $projects[$pid]['code'] ?? '', $this->repo->memberProjects($uid));
      $team[] = $p + [
        'username' => $account->getAccountName(),
        'projects' => $account->hasPermission('view all projects') ? 'All projects' : ($codes ? implode(', ', $codes) : '—'),
        'last' => $account->getLastAccessedTime() ? Format::ago((int) $account->getLastAccessedTime()) : 'never',
      ];
    }

    $canEdit = $a->hasPermission('manage sthira settings');
    return [
      '#theme' => 'sthira_access',
      '#roles' => $roles,
      '#columns' => array_values($columns),
      '#limits_form' => $canEdit ? $this->formBuilder()->getForm(LimitsForm::class) : NULL,
      '#limit' => '',
      '#team' => $team,
      '#can_edit' => $canEdit,
      '#urls' => [
        'permissions' => $a->hasPermission('administer permissions') ? Url::fromRoute('user.admin_permissions')->toString() : NULL,
        'content' => $a->hasPermission('access content overview') ? Url::fromRoute('system.admin_content')->toString() : NULL,
        'people' => $a->hasPermission('administer users') ? Url::fromRoute('entity.user.collection')->toString() : NULL,
        'add_person' => $a->hasPermission('administer users') ? Url::fromRoute('user.admin_create')->toString() : NULL,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function audit() {
    $a = $this->currentUser();
    $pids = $a->hasPermission('view all projects') ? NULL : $this->repo->accessibleProjectIds($a);
    return [
      '#theme' => 'sthira_audit',
      '#logs' => $this->logRows($this->repo->logs(NULL, 150, $pids)),
      '#cache' => ['max-age' => 0],
    ];
  }

  /* ---------------------------------------------------------------------
   * Helpers.
   * ------------------------------------------------------------------- */

  protected function canSeeRequest(int $id, $account): bool {
    $r = $this->repo->request($id);
    return $r && in_array((int) $r['project_id'], $this->repo->accessibleProjectIds($account), TRUE);
  }

  protected function roleKey($a): string {
    if ((int) $a->id() === 1) {
      return 'admin';
    }
    foreach (['director', 'project_manager', 'procurement_officer', 'vendor'] as $rid) {
      if (in_array($rid, $a->getRoles(), TRUE)) {
        return $rid;
      }
    }
    return 'admin';
  }

  protected function rows(array $requests, $a): array {
    $items = $this->repo->items();
    $projects = $this->repo->projects();
    $finance = $a->hasPermission('view finance');
    $out = [];
    foreach ($requests as $r) {
      $stage = Workflow::stage($r['stage']);
      $item = $items[$r['item_id']] ?? ['name' => '?', 'category' => ''];
      $own = (int) $r['created_by'] === (int) $a->id();
      $out[] = [
        'ref' => $r['ref'],
        'title' => Format::qty($r['qty']) . ' ' . $r['unit'] . ' · ' . $this->assistant->shortItem($item['name']),
        'item' => $item['name'],
        'category' => $item['category'],
        'project' => $this->assistant->shortProject($projects[$r['project_id']]['name'] ?? ''),
        'project_code' => $projects[$r['project_id']]['code'] ?? '',
        'amount' => ($finance || $own) ? Format::short($r['po_amount'] ?: $r['est_amount']) : NULL,
        'stage' => $stage['label'],
        'action' => Workflow::ACTIONS[$r['stage']] ?? 'Open',
        'tone' => $stage['tone'],
        'age' => Format::ago((int) $r['updated']),
        'needed' => Format::day($r['needed_by']),
        'urgent' => $r['priority'] === 'urgent',
        'by' => $this->repo->person((int) $r['created_by']),
        'ai' => !empty($r['raw_text']),
        'mismatch' => $r['match_status'] === 'mismatch' && $r['stage'] === 'invoiced',
        'url' => Url::fromRoute('sthira.request', ['id' => $r['id']])->toString(),
      ];
    }
    return $out;
  }

  protected function insightRows(array $insights): array {
    $icons = Icons::all();
    foreach ($insights as &$i) {
      $i['icon'] = $icons[$i['icon']] ?? $icons['sparkle'];
    }
    return $insights;
  }

  protected function logRows(array $logs, bool $mask = FALSE): array {
    $labels = [
      'created' => ['Raised', 'neutral'],
      'pm_approved' => ['Approved', 'ok'],
      'director_approved' => ['Approved', 'ok'],
      'rejected' => ['Rejected', 'bad'],
      'po_issued' => ['PO issued', 'info'],
      'received' => ['Delivered', 'info'],
      'invoice_checked' => ['Invoice', 'violet'],
      'paid' => ['Paid', 'ok'],
      'held' => ['On hold', 'warn'],
      'notified' => ['Alert', 'neutral'],
      'settings' => ['Settings', 'warn'],
      'vendor_registered' => ['Registered', 'info'],
      'vendor_approved' => ['Vendor approved', 'ok'],
      'vendor_rejected' => ['Vendor rejected', 'bad'],
      'vendor_rate' => ['Vendor rate', 'violet'],
      'vendor_profile' => ['Vendor profile', 'neutral'],
    ];
    $out = [];
    foreach ($logs as $l) {
      $who = $this->repo->person((int) $l['uid']);
      [$label, $tone] = $labels[$l['action']] ?? [ucfirst($l['action']), 'neutral'];
      $out[] = [
        'who' => $who,
        'action' => $label,
        'tone' => $tone,
        'message' => $mask ? preg_replace('/₹[\d,]+(\.\d+)?( (L|Cr))?(\/[a-z.]+)?/u', '₹•••', $l['message']) : $l['message'],
        'ref' => $l['ref'] ?? NULL,
        'url' => !empty($l['request_id']) ? Url::fromRoute('sthira.request', ['id' => $l['request_id']])->toString() : NULL,
        'ago' => Format::ago((int) $l['created']),
        'time' => date('d M Y, H:i', (int) $l['created']),
      ];
    }
    return $out;
  }

  /**
   * "Edit in Drupal" link for people who can edit content directly.
   */
  protected function editUrl(int $nid): ?string {
    $node = $this->entityTypeManager()->getStorage('node')->load($nid);
    return $node && $node->access('update')
      ? Url::fromRoute('entity.node.edit_form', ['node' => $nid], ['query' => ['destination' => $this->getRequestPath()]])->toString()
      : NULL;
  }

  /**
   * "Add …" links to Drupal's content forms, for bundles this person may create.
   *
   * After saving, Drupal sends them back to the app page they came from.
   */
  protected function addLinks(array $bundles): array {
    $labels = [
      'material_request' => 'Material request',
      'project' => 'Project',
      'material' => 'Material',
      'vendor' => 'Supplier',
      'vendor_rate' => 'Supplier price',
      'site_stock' => 'Site stock',
    ];
    $back = $this->getRequestPath();
    $handler = $this->entityTypeManager()->getAccessControlHandler('node');
    $out = [];
    foreach ($bundles as $bundle) {
      if ($handler->createAccess($bundle, $this->currentUser())) {
        $out[] = [
          'label' => $labels[$bundle],
          'url' => Url::fromRoute('node.add', ['node_type' => $bundle], ['query' => ['destination' => $back]])->toString(),
        ];
      }
    }
    return $out;
  }

  protected function getRequestPath(): string {
    return \Drupal::request()->getPathInfo();
  }

  protected function urls(): array {
    return [
      'requests' => Url::fromRoute('sthira.requests')->toString(),
      'new' => Url::fromRoute('sthira.request_new')->toString(),
      'insights' => Url::fromRoute('sthira.insights')->toString(),
      'projects' => Url::fromRoute('sthira.projects')->toString(),
      'audit' => Url::fromRoute('sthira.audit')->toString(),
    ];
  }

}
