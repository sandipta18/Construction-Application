<?php

namespace Drupal\sthira;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The purchase workflow: stages, who acts on each, approval routing, matching.
 */
class Workflow {

  const STAGES = [
    'pm_review' => ['label' => 'Needs approval', 'long' => 'Waiting for the project manager to approve', 'tone' => 'warn'],
    'director_review' => ['label' => 'Needs Director', 'long' => 'Waiting for the Director to approve', 'tone' => 'warn'],
    'approved' => ['label' => 'Ready to order', 'long' => 'Approved · ready to order', 'tone' => 'info'],
    'ordered' => ['label' => 'Ordered', 'long' => 'Ordered · waiting for delivery', 'tone' => 'info'],
    'received' => ['label' => 'Delivered', 'long' => 'Delivered · bill to be checked', 'tone' => 'violet'],
    'invoiced' => ['label' => 'Ready to pay', 'long' => 'Bill checked · ready to pay', 'tone' => 'violet'],
    'paid' => ['label' => 'Paid', 'long' => 'Done · supplier paid', 'tone' => 'ok'],
    'rejected' => ['label' => 'Not approved', 'long' => 'Not approved', 'tone' => 'bad'],
  ];

  /**
   * The one thing to do at each stage, as a button label.
   */
  const ACTIONS = [
    'pm_review' => 'Approve',
    'director_review' => 'Approve',
    'approved' => 'Place order',
    'ordered' => 'Confirm delivery',
    'received' => 'Check bill',
    'invoiced' => 'Pay supplier',
  ];

  /**
   * Permission needed to act on each stage.
   */
  const STAGE_PERMISSION = [
    'pm_review' => 'approve requests',
    'director_review' => 'approve high value requests',
    'approved' => 'issue purchase orders',
    'ordered' => 'receive goods',
    'received' => 'process invoices',
    'invoiced' => 'process invoices',
  ];

  /**
   * Role that normally owns each stage (for "waiting on" and notifications).
   */
  const STAGE_ROLE = [
    'pm_review' => 'project_manager',
    'director_review' => 'director',
    'approved' => 'procurement_officer',
    'ordered' => 'project_manager',
    'received' => 'procurement_officer',
    'invoiced' => 'procurement_officer',
  ];

  /**
   * Stages scoped to project membership (the project's PM).
   */
  const PROJECT_SCOPED = ['pm_review', 'ordered'];

  public function __construct(protected ConfigFactoryInterface $configFactory, protected Repository $repo) {}

  public function pmLimit(): int {
    return (int) ($this->configFactory->get('sthira.settings')->get('pm_limit') ?: 50000);
  }

  public function demoMode(): bool {
    return (bool) $this->configFactory->get('sthira.settings')->get('demo_mode');
  }

  public function needsDirector(array $r): bool {
    return (float) $r['est_amount'] > $this->pmLimit();
  }

  public static function stage(string $key): array {
    return self::STAGES[$key] ?? ['label' => $key, 'long' => $key, 'tone' => 'neutral'];
  }

  public function canAct(array $r, AccountInterface $a): bool {
    $perm = self::STAGE_PERMISSION[$r['stage']] ?? NULL;
    if (!$perm || !$a->hasPermission($perm)) {
      return FALSE;
    }
    if (in_array($r['stage'], self::PROJECT_SCOPED, TRUE)) {
      return in_array((int) $r['project_id'], $this->repo->accessibleProjectIds($a), TRUE);
    }
    return TRUE;
  }

  /**
   * First stage of a new request raised by $a.
   *
   * Raising a request is the raiser's own approval when they could approve
   * it anyway (the project's PM, or the Director). So a PM's request goes
   * straight to purchasing, or to the Director when it is above the PM limit.
   *
   * @return array
   *   ['stage' => string, 'approved_pm' => ?int, 'approved_director' => ?int]
   */
  public function startStage(array $r, AccountInterface $a): array {
    $out = ['stage' => 'pm_review', 'approved_pm' => NULL, 'approved_director' => NULL];
    if (!$this->canAct(['stage' => 'pm_review'] + $r, $a)) {
      return $out;
    }
    $out['approved_pm'] = (int) $a->id();
    if ($this->needsDirector($r)) {
      if (!$a->hasPermission('approve high value requests')) {
        $out['stage'] = 'director_review';
        return $out;
      }
      $out['approved_director'] = (int) $a->id();
    }
    $out['stage'] = 'approved';
    return $out;
  }

  public function queueStages(AccountInterface $a): array {
    if (in_array('director', $a->getRoles(), TRUE)) {
      return ['director_review'];
    }
    $out = [];
    foreach (self::STAGE_PERMISSION as $stage => $perm) {
      if ($a->hasPermission($perm)) {
        $out[] = $stage;
      }
    }
    return $out;
  }

  /**
   * Requests waiting on this person.
   */
  public function queue(AccountInterface $a): array {
    $stages = $this->queueStages($a);
    if (!$stages) {
      return [];
    }
    $rows = $this->repo->requests($this->repo->accessibleProjectIds($a), $stages);
    return array_filter($rows, fn($r) => $this->canAct($r, $a));
  }

  /**
   * Who the request is waiting on: role label and names.
   */
  public function waitingOn(array $r): ?array {
    $role = self::STAGE_ROLE[$r['stage']] ?? NULL;
    if (!$role) {
      return NULL;
    }
    $uids = $this->repo->usersWithRole($role);
    if (in_array($r['stage'], self::PROJECT_SCOPED, TRUE)) {
      $uids = array_values(array_intersect($uids, $this->repo->members((int) $r['project_id'])));
    }
    $people = array_map(fn($uid) => $this->repo->person($uid), $uids);
    $roleEntity = \Drupal::entityTypeManager()->getStorage('user_role')->load($role);
    return [
      'role' => $roleEntity ? $roleEntity->label() : $role,
      'people' => $people,
      'names' => implode(', ', array_column($people, 'name')),
      'task' => [
        'pm_review' => 'approve it',
        'director_review' => 'approve it',
        'approved' => 'place the order',
        'ordered' => 'confirm the delivery',
        'received' => 'check the bill',
        'invoiced' => 'pay the supplier',
      ][$r['stage']] ?? '',
    ];
  }

  /**
   * Builds the stepper for a request.
   */
  public function steps(array $r, array $logs): array {
    $defs = [
      'requested' => 'Requested',
      'pm_review' => 'PM approval',
    ];
    if ($this->needsDirector($r) || $r['stage'] === 'director_review' || !empty($r['approved_director'])) {
      $defs['director_review'] = 'Director approval';
    }
    $defs += [
      'approved' => 'Order',
      'ordered' => 'Delivery',
      'received' => 'Bill check',
      'invoiced' => 'Payment',
    ];
    $doneAction = [
      'requested' => 'created',
      'pm_review' => 'pm_approved',
      'director_review' => 'director_approved',
      'approved' => 'po_issued',
      'ordered' => 'received',
      'received' => 'invoice_checked',
      'invoiced' => 'paid',
    ];
    $when = [];
    foreach (array_reverse($logs) as $l) {
      $when[$l['action']] = $l;
    }
    $keys = array_keys($defs);
    $stage = $r['stage'];
    $currentIndex = match ($stage) {
      'paid' => count($keys),
      'rejected' => array_search($r['rejected_stage'] ?: 'pm_review', $keys, TRUE),
      default => array_search($stage, $keys, TRUE),
    };
    if ($currentIndex === FALSE) {
      $currentIndex = 1;
    }
    $steps = [];
    foreach ($keys as $i => $key) {
      if ($i < $currentIndex || $key === 'requested') {
        $state = 'done';
      }
      elseif ($i === $currentIndex) {
        $state = $stage === 'rejected' ? 'rejected' : 'current';
      }
      else {
        $state = 'todo';
      }
      $meta = '';
      $log = $when[$doneAction[$key]] ?? NULL;
      if ($state === 'done' && $log) {
        $meta = $this->repo->person((int) $log['uid'])['name'] . ' · ' . date('d M', (int) $log['created']);
      }
      elseif ($state === 'current') {
        $meta = 'In progress';
      }
      elseif ($state === 'rejected') {
        $meta = 'Rejected';
      }
      $steps[] = ['label' => $defs[$key], 'state' => $state, 'meta' => $meta];
    }
    return $steps;
  }

  /**
   * Three-way match: purchase order vs goods receipt vs invoice.
   */
  public function match(array $r): array {
    $poQty = (float) $r['qty'];
    $poRate = (float) $r['po_rate'];
    $grnQty = (float) $r['received_qty'];
    $invQty = (float) $r['invoice_qty'];
    $invAmount = (float) $r['invoice_amount'];
    $invRate = $invQty > 0 ? $invAmount / $invQty : 0;
    $grnValue = round($grnQty * $poRate);
    $unit = $r['unit'];

    $rows = [
      [
        'label' => 'Quantity',
        'po' => Format::qty($poQty) . ' ' . $unit,
        'grn' => Format::qty($grnQty) . ' ' . $unit,
        'inv' => Format::qty($invQty) . ' ' . $unit,
        'ok' => abs($invQty - $grnQty) < 0.001,
      ],
      [
        'label' => 'Rate',
        'po' => Format::inr($poRate) . '/' . Format::per($unit),
        'grn' => '—',
        'inv' => Format::inr($invRate) . '/' . Format::per($unit),
        'ok' => abs($invRate - $poRate) < 0.5,
      ],
      [
        'label' => 'Amount',
        'po' => Format::inr($r['po_amount']),
        'grn' => Format::inr($grnValue),
        'inv' => Format::inr($invAmount),
        'ok' => abs($invAmount - $grnValue) < 1,
      ],
    ];
    $notes = [];
    if ($invQty > $grnQty) {
      $notes[] = 'The bill is for ' . Format::qty($invQty) . ' ' . $unit . ' but only ' . Format::qty($grnQty) . ' ' . $unit . ' arrived at site.';
    }
    if ($grnQty < $poQty) {
      $notes[] = Format::qty($poQty - $grnQty) . ' ' . $unit . ' of the order have not arrived yet.';
    }
    if (abs($invRate - $poRate) >= 0.5) {
      $notes[] = 'The bill price ' . Format::inr($invRate) . ' is different from the agreed ' . Format::inr($poRate) . '.';
    }
    $ok = !in_array(FALSE, array_column($rows, 'ok'), TRUE);
    return [
      'status' => $ok ? 'matched' : 'mismatch',
      'rows' => $rows,
      'notes' => $notes,
      'payable' => $grnValue,
      'payable_label' => Format::inr($grnValue),
      'invoice_label' => Format::inr($invAmount),
      'difference' => Format::inr(max(0, $invAmount - $grnValue)),
    ];
  }

}
