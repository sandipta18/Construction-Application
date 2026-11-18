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
    'approved' => ['label' => 'Ready for bids', 'long' => 'Approved · ready to invite bids', 'tone' => 'info'],
    'bidding' => ['label' => 'Collecting bids', 'long' => 'Suppliers are sending their prices', 'tone' => 'info'],
    'order_review' => ['label' => 'Order approval', 'long' => 'Winning bid waiting for the project manager', 'tone' => 'warn'],
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
    'approved' => 'Invite bids',
    'bidding' => 'Choose winner',
    'order_review' => 'Approve order',
    'ordered' => 'Confirm delivery',
    'received' => 'Check bill',
    'invoiced' => 'Pay supplier',
  ];

  /**
   * Permission needed to act on each stage.
   */
  const STAGE_PERMISSION = [
    'pm_review' => 'approve requests',
    'order_review' => 'approve orders',
    'approved' => 'issue purchase orders',
    'bidding' => 'issue purchase orders',
    'ordered' => 'receive goods',
    'received' => 'process invoices',
    'invoiced' => 'process invoices',
  ];

  /**
   * Role that normally owns each stage (for "waiting on" and notifications).
   */
  const STAGE_ROLE = [
    'pm_review' => 'project_manager',
    'order_review' => 'project_manager',
    'approved' => 'procurement_officer',
    'bidding' => 'procurement_officer',
    'ordered' => 'project_manager',
    'received' => 'procurement_officer',
    'invoiced' => 'procurement_officer',
  ];

  /**
   * Stages scoped to project membership (the project's PM). The PM who raised
   * the request approves the order and confirms the delivery at site;
   * Procurement checks the bill and pays. So no one person both orders,
   * receives and pays.
   */
  const PROJECT_SCOPED = ['pm_review', 'order_review', 'ordered'];

  /**
   * Stages that belong to the PM who raised the request.
   */
  const RAISER_STAGES = ['order_review', 'ordered'];

  public function __construct(protected ConfigFactoryInterface $configFactory, protected Repository $repo) {}

  public function pmLimit(): int {
    return (int) ($this->configFactory->get('sthira.settings')->get('pm_limit') ?: 50000);
  }

  public function demoMode(): bool {
    return (bool) $this->configFactory->get('sthira.settings')->get('demo_mode');
  }

  /**
   * Who approves the order: the PM who raised the request, if they are a PM
   * on that project; otherwise the project's PMs.
   *
   * @return int[]
   */
  public function orderApprovers(array $r): array {
    $pms = array_values(array_intersect($this->repo->usersWithRole('project_manager'), $this->repo->members((int) $r['project_id'])));
    $raiser = (int) ($r['created_by'] ?? 0);
    return in_array($raiser, $pms, TRUE) ? [$raiser] : $pms;
  }

  public static function stage(string $key): array {
    return self::STAGES[$key] ?? ['label' => $key, 'long' => $key, 'tone' => 'neutral'];
  }

  public function canAct(array $r, AccountInterface $a): bool {
    $perm = self::STAGE_PERMISSION[$r['stage']] ?? NULL;
    if (!$perm || !$a->hasPermission($perm)) {
      return FALSE;
    }
    if (in_array($r['stage'], self::RAISER_STAGES, TRUE)) {
      return in_array((int) $a->id(), $this->orderApprovers($r), TRUE);
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
   * it anyway (the project's PM). So a PM's request goes straight to
   * Procurement for bids. The same PM approves the order later, once
   * Procurement has chosen the winning bid.
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
    $out['stage'] = 'approved';
    return $out;
  }

  public function queueStages(AccountInterface $a): array {
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
    // While bidding is open, the request waits on suppliers, not on anyone here.
    return array_filter($rows, fn($r) => $this->canAct($r, $a) && !($r['stage'] === 'bidding' && empty($r['bids_closed'])));
  }

  /**
   * Who the request is waiting on: role label and names.
   */
  public function waitingOn(array $r): ?array {
    if ($r['stage'] === 'bidding' && empty($r['bids_closed'])) {
      $vendors = $this->repo->vendors();
      $bidders = array_column($this->repo->bids((int) $r['id']), 'vendor_id');
      $names = array_map(fn($v) => $vendors[$v]['name'] ?? '', array_diff($r['invited'], $bidders));
      return ['role' => 'Suppliers', 'people' => [], 'names' => implode(', ', $names), 'task' => 'send their bids'];
    }
    $role = self::STAGE_ROLE[$r['stage']] ?? NULL;
    if (!$role) {
      return NULL;
    }
    $uids = $this->repo->usersWithRole($role);
    if (in_array($r['stage'], self::RAISER_STAGES, TRUE)) {
      $uids = $this->orderApprovers($r);
    }
    elseif (in_array($r['stage'], self::PROJECT_SCOPED, TRUE)) {
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
        'approved' => 'invite bids from suppliers',
        'bidding' => 'choose the winning bid',
        'order_review' => 'approve the order',
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
      'approved' => 'Invite bids',
      'bidding' => ($r['stage'] === 'bidding' && empty($r['bids_closed'])) ? 'Collect bids' : 'Choose winner',
    ];
    $defs += [
      'order_review' => 'Order approval',
      'ordered' => 'Delivery',
      'received' => 'Bill check',
      'invoiced' => 'Payment',
    ];
    $doneAction = [
      'requested' => 'created',
      'pm_review' => 'pm_approved',
      'approved' => 'bids_invited',
      'bidding' => 'awarded',
      'order_review' => 'order_approved',
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
