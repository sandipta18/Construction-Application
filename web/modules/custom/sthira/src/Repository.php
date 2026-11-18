<?php

namespace Drupal\sthira;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Data access for Sthira.
 *
 * Projects, materials, vendors, vendor rates, site stock and material
 * requests are regular Drupal content types (nodes). This class maps them to
 * plain arrays for the screens and the workflow, and writes changes back to
 * the nodes. Only the audit log is a custom table.
 */
class Repository {

  /**
   * Stages where a request is still moving and stock is not yet on site.
   */
  const OPEN_STAGES = ['pm_review', 'order_review', 'approved', 'ordered'];

  /**
   * Stages where money is committed to a vendor.
   */
  const COMMITTED_STAGES = ['ordered', 'received', 'invoiced', 'paid'];

  /**
   * Material categories: machine name => label (matches field allowed values).
   */
  const CATEGORIES = [
    'steel' => 'Steel',
    'cement' => 'Cement',
    'aggregates' => 'Aggregates',
    'bricks' => 'Bricks & Blocks',
    'electrical' => 'Electrical',
    'plumbing' => 'Plumbing',
    'finishing' => 'Finishing',
  ];

  /**
   * Request array key => node field.
   */
  const REQUEST_FIELDS = [
    'ref' => 'field_ref',
    'project_id' => 'field_project',
    'item_id' => 'field_material',
    'qty' => 'field_qty',
    'unit' => 'field_unit',
    'est_rate' => 'field_est_rate',
    'est_amount' => 'field_est_amount',
    'needed_by' => 'field_needed_by',
    'priority' => 'field_priority',
    'note' => 'field_note',
    'raw_text' => 'field_raw_text',
    'stage' => 'field_stage',
    'rejected_stage' => 'field_rejected_stage',
    'vendor_id' => 'field_vendor',
    'po_number' => 'field_po_number',
    'po_rate' => 'field_po_rate',
    'po_amount' => 'field_po_amount',
    'po_date' => 'field_po_date',
    'received_qty' => 'field_received_qty',
    'grn_date' => 'field_grn_date',
    'invoice_no' => 'field_invoice_no',
    'invoice_qty' => 'field_invoice_qty',
    'invoice_amount' => 'field_invoice_amount',
    'match_status' => 'field_match_status',
    'paid_amount' => 'field_paid_amount',
    'paid_date' => 'field_paid_date',
    'approved_pm' => 'field_approved_pm',
    'approved_director' => 'field_approved_director',
    'bids_opened' => 'field_bids_opened',
    'bids_closed' => 'field_bids_closed',
  ];

  protected array $memo = [];

  public function __construct(
    protected Connection $db,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /* ---------- Node helpers ---------- */

  protected function storage() {
    return $this->entityTypeManager->getStorage('node');
  }

  /**
   * Loads nodes of a type. Conditions: [field, value, operator].
   */
  protected function nodes(string $type, array $conditions = [], array $sort = [], int $limit = 0): array {
    $q = $this->storage()->getQuery()->accessCheck(FALSE)->condition('type', $type);
    foreach ($conditions as $c) {
      [$field, $value] = $c;
      $op = $c[2] ?? (is_array($value) ? 'IN' : '=');
      if ($op === 'IN' && !$value) {
        return [];
      }
      $q->condition($field, $value, $op);
    }
    foreach ($sort as $field => $dir) {
      $q->sort($field, $dir);
    }
    $q->sort('nid', 'ASC');
    if ($limit) {
      $q->range(0, $limit);
    }
    $ids = $q->execute();
    return $ids ? $this->storage()->loadMultiple($ids) : [];
  }

  protected function v(NodeInterface $n, string $field) {
    if (!$n->hasField($field) || $n->get($field)->isEmpty()) {
      return NULL;
    }
    $item = $n->get($field)->first();
    return $item->getFieldDefinition()->getType() === 'entity_reference' ? (int) $item->target_id : $item->value;
  }

  protected function values(NodeInterface $n, string $field): array {
    $out = [];
    foreach ($n->get($field) as $item) {
      $out[] = $item->target_id ?? $item->value;
    }
    return $out;
  }

  /* ---------- People ---------- */

  public function person(int $uid): array {
    $people = $this->state->get('sthira.people', []);
    $p = $people[$uid] ?? NULL;
    if (!$p) {
      $name = $uid ? ($this->db->select('users_field_data', 'u')->fields('u', ['name'])->condition('uid', $uid)->execute()->fetchField() ?: 'User') : 'System';
      $p = ['name' => ucfirst($name), 'title' => 'Team member'];
    }
    $p['uid'] = $uid;
    $p['initials'] = Format::initials($p['name']);
    return $p;
  }

  public function demoUids(): array {
    return $this->state->get('sthira.demo_uids', []);
  }

  public function usersWithRole(string $role): array {
    return array_map('intval', $this->db->select('user__roles', 'r')
      ->fields('r', ['entity_id'])
      ->condition('roles_target_id', $role)
      ->execute()->fetchCol());
  }

  /* ---------- Projects ---------- */

  protected function projectArray(NodeInterface $n): array {
    return [
      'id' => (int) $n->id(),
      'code' => $this->v($n, 'field_code') ?? '',
      'name' => $n->label(),
      'location' => $this->v($n, 'field_location') ?? '',
      'client' => $this->v($n, 'field_client') ?? '',
      'budget' => (float) $this->v($n, 'field_contract_value'),
      'progress' => (int) $this->v($n, 'field_progress'),
      'phase' => $this->v($n, 'field_phase') ?? '',
      'description' => $this->v($n, 'field_description') ?? '',
      'start_date' => $this->v($n, 'field_start_date') ?? date('Y-m-d'),
      'end_date' => $this->v($n, 'field_end_date') ?? date('Y-m-d'),
      'team' => array_map('intval', $this->values($n, 'field_team')),
      'photo' => ($n->hasField('field_photo') && ($file = $n->get('field_photo')->entity)) ? $file->createFileUrl() : NULL,
      'budgets' => $this->budgetFields($n),
    ];
  }

  protected function budgetFields(NodeInterface $n): array {
    $out = [];
    foreach (self::CATEGORIES as $key => $label) {
      $amount = (float) $this->v($n, 'field_budget_' . $key);
      if ($amount > 0) {
        $out[$label] = $amount;
      }
    }
    arsort($out);
    return $out;
  }

  protected function allProjects(): array {
    if (!isset($this->memo['projects'])) {
      $this->memo['projects'] = [];
      foreach ($this->nodes('project') as $n) {
        $this->memo['projects'][(int) $n->id()] = $this->projectArray($n);
      }
    }
    return $this->memo['projects'];
  }

  public function projects(?array $ids = NULL): array {
    $all = $this->allProjects();
    if ($ids === NULL) {
      return $all;
    }
    return array_intersect_key($all, array_flip($ids));
  }

  public function project(int $id): ?array {
    return $this->allProjects()[$id] ?? NULL;
  }

  public function accessibleProjectIds(AccountInterface $a): array {
    if ($a->hasPermission('view all projects')) {
      return array_keys($this->allProjects());
    }
    return $this->memberProjects((int) $a->id());
  }

  public function members(int $pid): array {
    return $this->project($pid)['team'] ?? [];
  }

  public function memberProjects(int $uid): array {
    $out = [];
    foreach ($this->allProjects() as $id => $p) {
      if (in_array($uid, $p['team'], TRUE)) {
        $out[] = $id;
      }
    }
    return $out;
  }

  public function budgets(int $pid): array {
    return $this->project($pid)['budgets'] ?? [];
  }

  public function budgetTotal(int $pid): float {
    return (float) array_sum($this->budgets($pid));
  }

  /**
   * Committed spend per category for a project.
   */
  public function committedByCategory(int $pid): array {
    $items = $this->items();
    $out = [];
    foreach ($this->requests([$pid], self::COMMITTED_STAGES) as $r) {
      $cat = $items[$r['item_id']]['category'] ?? 'Other';
      $out[$cat] = ($out[$cat] ?? 0) + (float) $r['po_amount'];
    }
    return $out;
  }

  public function committedTotal(array $pids): float {
    return (float) array_sum(array_map(fn($r) => (float) $r['po_amount'], $this->requests($pids, self::COMMITTED_STAGES)));
  }

  /* ---------- Catalog & vendors ---------- */

  public function items(): array {
    if (!isset($this->memo['items'])) {
      $this->memo['items'] = [];
      foreach ($this->nodes('material') as $n) {
        $cat = $this->v($n, 'field_category');
        $this->memo['items'][(int) $n->id()] = [
          'id' => (int) $n->id(),
          'name' => $n->label(),
          'category' => self::CATEGORIES[$cat] ?? 'Other',
          'unit' => $this->v($n, 'field_unit') ?? 'units',
          'std_rate' => (float) $this->v($n, 'field_std_rate'),
          'keywords' => $this->v($n, 'field_keywords') ?? strtolower($n->label()),
          'description' => $this->v($n, 'field_description') ?? '',
        ];
      }
    }
    return $this->memo['items'];
  }

  public function item(int $id): ?array {
    return $this->items()[$id] ?? NULL;
  }

  public function vendors(): array {
    if (!isset($this->memo['vendors'])) {
      $this->memo['vendors'] = [];
      foreach ($this->nodes('vendor', [], ['title' => 'ASC']) as $n) {
        $this->memo['vendors'][(int) $n->id()] = [
          'id' => (int) $n->id(),
          'name' => $n->label(),
          'city' => $this->v($n, 'field_location') ?? '',
          'gstin' => $this->v($n, 'field_gstin') ?? '',
          'contact' => $this->v($n, 'field_contact') ?? '',
          'phone' => $this->v($n, 'field_phone') ?? '',
          'categories' => implode(', ', array_map(fn($k) => self::CATEGORIES[$k] ?? $k, $this->values($n, 'field_supplies'))),
          'category_keys' => $this->values($n, 'field_supplies'),
          'rating' => (float) $this->v($n, 'field_rating'),
          'on_time' => (int) $this->v($n, 'field_on_time'),
          'orders' => (int) $this->v($n, 'field_order_count'),
          'contract_expiry' => $this->v($n, 'field_contract_expiry') ?? '',
          'status' => $this->v($n, 'field_vendor_status') ?? 'approved',
          'user_id' => $this->v($n, 'field_vendor_user'),
          'pan' => $this->v($n, 'field_pan') ?? '',
          'bank' => $this->v($n, 'field_bank') ?? '',
          'review_note' => $this->v($n, 'field_review_note') ?? '',
          'documents' => $this->documents($n),
          'created' => (int) $n->getCreatedTime(),
        ];
      }
    }
    return $this->memo['vendors'];
  }

  protected function documents(NodeInterface $n): array {
    $out = [];
    foreach ($n->get('field_documents') as $item) {
      if ($file = $item->entity) {
        $out[] = ['name' => $file->getFilename(), 'url' => $file->createFileUrl()];
      }
    }
    return $out;
  }

  /**
   * Vendors by verification status.
   */
  public function vendorsByStatus(string $status): array {
    return array_filter($this->vendors(), fn($v) => $v['status'] === $status);
  }

  /**
   * The vendor company a vendor login belongs to.
   */
  public function vendorForUser(int $uid): ?array {
    foreach ($this->vendors() as $v) {
      if ($v['user_id'] === $uid) {
        return $v;
      }
    }
    return NULL;
  }

  public function vendorNode(int $id): ?NodeInterface {
    $n = $this->storage()->load($id);
    return ($n instanceof NodeInterface && $n->bundle() === 'vendor') ? $n : NULL;
  }

  /**
   * Rate cards of one vendor.
   */
  public function vendorRates(int $vendorId): array {
    $items = $this->items();
    $out = [];
    foreach ($this->nodes('vendor_rate', [['field_vendor', $vendorId]]) as $n) {
      $item = $items[$this->v($n, 'field_material')] ?? NULL;
      $out[] = [
        'id' => (int) $n->id(),
        'item' => $item['name'] ?? '?',
        'item_id' => (int) $this->v($n, 'field_material'),
        'unit' => $item['unit'] ?? '',
        'rate' => (float) $this->v($n, 'field_rate'),
        'lead_days' => (int) $this->v($n, 'field_lead_days'),
        'updated' => (int) $n->getChangedTime(),
      ];
    }
    return $out;
  }

  public function vendor(int $id): ?array {
    return $this->vendors()[$id] ?? NULL;
  }

  public function quotesForItem(int $itemId): array {
    $vendors = $this->vendors();
    $out = [];
    foreach ($this->nodes('vendor_rate', [['field_material', $itemId]]) as $n) {
      $v = $vendors[$this->v($n, 'field_vendor')] ?? NULL;
      // Only verified vendors can be recommended or ordered from.
      if (!$v || $v['status'] !== 'approved') {
        continue;
      }
      $out[] = [
        'vendor_id' => $v['id'],
        'rate' => (float) $this->v($n, 'field_rate'),
        'lead_days' => (int) $this->v($n, 'field_lead_days'),
        'name' => $v['name'],
        'city' => $v['city'],
        'rating' => $v['rating'],
        'on_time' => $v['on_time'],
        'contract_expiry' => $v['contract_expiry'],
      ];
    }
    return $out;
  }

  public function vendorSpend(int $vendorId, int $sinceDays = 0): float {
    $conditions = [['field_vendor', $vendorId], ['field_stage', self::COMMITTED_STAGES]];
    if ($sinceDays) {
      $conditions[] = ['field_po_date', time() - $sinceDays * 86400, '>='];
    }
    return (float) array_sum(array_map(fn($n) => (float) $this->v($n, 'field_po_amount'), $this->nodes('material_request', $conditions)));
  }

  /* ---------- Requests ---------- */

  public function requestArray(NodeInterface $n): array {
    $r = ['id' => (int) $n->id()];
    foreach (self::REQUEST_FIELDS as $key => $field) {
      $r[$key] = $this->v($n, $field);
    }
    $r['ref'] = $r['ref'] ?: 'MR-' . $n->id();
    $r['stage'] = $r['stage'] ?: 'pm_review';
    $r['priority'] = $r['priority'] ?: 'normal';
    $r['note'] = $r['note'] ?? '';
    $r['raw_text'] = $r['raw_text'] ?? '';
    $r['unit'] = $r['unit'] ?? '';
    $r['needed_by'] = $r['needed_by'] ?? date('Y-m-d', strtotime('+7 days'));
    $r['invited'] = array_map('intval', $this->values($n, 'field_invited'));
    $r['created_by'] = (int) $n->getOwnerId();
    $r['created'] = (int) $n->getCreatedTime();
    $r['updated'] = (int) $n->getChangedTime();
    return $r;
  }

  public function requests(?array $projectIds = NULL, ?array $stages = NULL, int $limit = 0, ?int $createdBy = NULL): array {
    $conditions = [];
    if ($projectIds !== NULL) {
      if (!$projectIds) {
        return [];
      }
      $conditions[] = ['field_project', $projectIds];
    }
    if ($stages) {
      $conditions[] = ['field_stage', $stages];
    }
    if ($createdBy) {
      $conditions[] = ['uid', $createdBy];
    }
    $out = [];
    foreach ($this->nodes('material_request', $conditions, ['changed' => 'DESC'], $limit) as $n) {
      $out[(int) $n->id()] = $this->requestArray($n);
    }
    return $out;
  }

  public function request(int $id): ?array {
    $n = $this->storage()->load($id);
    return ($n instanceof NodeInterface && $n->bundle() === 'material_request') ? $this->requestArray($n) : NULL;
  }

  public function createRequest(array $values): int {
    $count = (int) $this->storage()->getQuery()->accessCheck(FALSE)->condition('type', 'material_request')->count()->execute();
    $values['ref'] = $values['ref'] ?? 'MR-' . (1041 + $count);
    $node = $this->storage()->create([
      'type' => 'material_request',
      'title' => $values['ref'],
      'uid' => $values['created_by'] ?? 1,
      'status' => 1,
    ]);
    $node->sthira_internal = TRUE;
    if (isset($values['created'])) {
      $node->setCreatedTime((int) $values['created']);
    }
    $node->setChangedTime((int) ($values['updated'] ?? $values['created'] ?? time()));
    $this->apply($node, $values);
    $node->save();
    return (int) $node->id();
  }

  public function updateRequest(int $id, array $values): void {
    $node = $this->storage()->load($id);
    if (!$node) {
      return;
    }
    $node->sthira_internal = TRUE;
    $this->apply($node, $values);
    $node->setChangedTime((int) ($values['updated'] ?? time()));
    $node->save();
  }

  protected function apply(NodeInterface $node, array $values): void {
    if (array_key_exists('invited', $values)) {
      $node->set('field_invited', array_map(fn($id) => ['target_id' => (int) $id], $values['invited'] ?: []));
    }
    foreach ($values as $key => $value) {
      $field = self::REQUEST_FIELDS[$key] ?? NULL;
      if (!$field) {
        continue;
      }
      if ($value === NULL || $value === '') {
        $node->set($field, NULL);
      }
      elseif (str_ends_with($field, '_id') || in_array($field, ['field_project', 'field_material', 'field_vendor', 'field_approved_pm', 'field_approved_director'], TRUE)) {
        $node->set($field, ['target_id' => (int) $value]);
      }
      else {
        $node->set($field, $value);
      }
    }
  }

  /* ---------- Bids ---------- */

  public function bidArray(NodeInterface $n): array {
    return [
      'id' => (int) $n->id(),
      'request_id' => (int) $this->v($n, 'field_request'),
      'vendor_id' => (int) $this->v($n, 'field_vendor'),
      'rate' => (float) $this->v($n, 'field_rate'),
      'lead_days' => (int) $this->v($n, 'field_lead_days'),
      'note' => $this->v($n, 'field_note') ?? '',
      'status' => $this->v($n, 'field_bid_status') ?? 'submitted',
      'uid' => (int) $n->getOwnerId(),
      'created' => (int) $n->getCreatedTime(),
      'changed' => (int) $n->getChangedTime(),
    ];
  }

  /**
   * Bids on one request, oldest first, keyed by bid id.
   */
  public function bids(int $requestId): array {
    $out = [];
    foreach ($this->nodes('bid', [['field_request', $requestId]], ['created' => 'ASC']) as $n) {
      $out[(int) $n->id()] = $this->bidArray($n);
    }
    return $out;
  }

  public function bidFor(int $requestId, int $vendorId): ?array {
    foreach ($this->bids($requestId) as $b) {
      if ($b['vendor_id'] === $vendorId) {
        return $b;
      }
    }
    return NULL;
  }

  /**
   * Creates or updates a vendor's bid on a request. Returns the bid id.
   */
  public function saveBid(int $requestId, int $vendorId, float $rate, int $leadDays, string $note, int $uid, ?int $time = NULL): int {
    $existing = $this->bidFor($requestId, $vendorId);
    $node = $existing ? $this->storage()->load($existing['id']) : $this->storage()->create([
      'type' => 'bid',
      'uid' => $uid,
      'status' => 1,
      'field_request' => ['target_id' => $requestId],
      'field_vendor' => ['target_id' => $vendorId],
      'field_bid_status' => 'submitted',
    ]);
    $request = $this->request($requestId);
    $vendor = $this->vendor($vendorId);
    $node->setTitle(($request['ref'] ?? 'MR') . ' · ' . ($vendor['name'] ?? 'Supplier'));
    $node->set('field_rate', (int) round($rate));
    $node->set('field_lead_days', $leadDays);
    $node->set('field_note', $note);
    if ($time) {
      $node->setCreatedTime($time);
      $node->setChangedTime($time);
    }
    $node->sthira_internal = TRUE;
    $node->save();
    return (int) $node->id();
  }

  /**
   * Marks the winning bid "won" and the others "not selected".
   */
  public function settleBids(int $requestId, ?int $winnerBidId): void {
    foreach ($this->nodes('bid', [['field_request', $requestId]]) as $n) {
      $n->set('field_bid_status', $winnerBidId === NULL ? 'submitted' : ((int) $n->id() === $winnerBidId ? 'won' : 'lost'));
      $n->sthira_internal = TRUE;
      $n->save();
    }
  }

  /**
   * Requests a vendor was invited to bid on (any stage), newest first.
   */
  public function invitedRequests(int $vendorId): array {
    $out = [];
    foreach ($this->nodes('material_request', [['field_invited', $vendorId]], ['changed' => 'DESC']) as $n) {
      $out[(int) $n->id()] = $this->requestArray($n);
    }
    return $out;
  }

  /**
   * Approved vendors who supply a category (by category label).
   */
  public function vendorsForCategory(string $categoryLabel): array {
    $key = array_search($categoryLabel, self::CATEGORIES, TRUE);
    return array_filter($this->vendors(), fn($v) => $v['status'] === 'approved' && in_array($key, $v['category_keys'], TRUE));
  }

  /**
   * Purchase orders placed with one vendor, newest first.
   */
  public function requestsForVendor(int $vendorId): array {
    $out = [];
    foreach ($this->nodes('material_request', [['field_vendor', $vendorId]], ['field_po_date' => 'DESC']) as $n) {
      $out[(int) $n->id()] = $this->requestArray($n);
    }
    return $out;
  }

  public function nextPoNumber(): string {
    $n = (int) $this->storage()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'material_request')->exists('field_po_number')->count()->execute();
    return 'PO-' . date('Y') . '-' . str_pad((string) (101 + $n), 4, '0', STR_PAD_LEFT);
  }

  /**
   * Purchase orders for an item over the last N days, oldest first.
   */
  public function poHistory(int $itemId, array $pids, int $days = 90): array {
    if (!$pids) {
      return [];
    }
    $out = [];
    foreach ($this->nodes('material_request', [
      ['field_material', $itemId],
      ['field_project', $pids],
      ['field_stage', self::COMMITTED_STAGES],
      ['field_po_date', time() - $days * 86400, '>='],
    ], ['field_po_date' => 'ASC']) as $n) {
      $out[] = $this->requestArray($n);
    }
    return $out;
  }

  public function hasOpenRequest(int $pid, int $itemId): bool {
    return (bool) $this->storage()->getQuery()->accessCheck(FALSE)
      ->condition('type', 'material_request')
      ->condition('field_project', $pid)
      ->condition('field_material', $itemId)
      ->condition('field_stage', self::OPEN_STAGES, 'IN')
      ->count()->execute();
  }

  /* ---------- Stock ---------- */

  public function stock(?int $pid = NULL, ?array $pids = NULL): array {
    $conditions = [];
    if ($pid) {
      $conditions[] = ['field_project', $pid];
    }
    if ($pids !== NULL) {
      if (!$pids) {
        return [];
      }
      $conditions[] = ['field_project', $pids];
    }
    $out = [];
    foreach ($this->nodes('site_stock', $conditions) as $n) {
      $out[] = [
        'id' => (int) $n->id(),
        'project_id' => (int) $this->v($n, 'field_project'),
        'item_id' => (int) $this->v($n, 'field_material'),
        'qty' => (float) $this->v($n, 'field_stock_qty'),
        'daily_use' => (float) $this->v($n, 'field_daily_use'),
      ];
    }
    return $out;
  }

  public function adjustStock(int $pid, int $itemId, float $delta): void {
    $existing = $this->nodes('site_stock', [['field_project', $pid], ['field_material', $itemId]], [], 1);
    if ($existing) {
      $node = reset($existing);
      $node->set('field_stock_qty', (float) $this->v($node, 'field_stock_qty') + $delta);
      $node->save();
      return;
    }
    $this->storage()->create([
      'type' => 'site_stock',
      'title' => 'Stock',
      'uid' => 1,
      'field_project' => $pid,
      'field_material' => $itemId,
      'field_stock_qty' => $delta,
      'field_daily_use' => 0,
    ])->save();
  }

  /* ---------- Audit log (custom table) ---------- */

  public function log(int $rid, int $uid, string $action, string $message, ?int $time = NULL): void {
    $ref = NULL;
    $pid = 0;
    if ($rid && ($r = $this->request($rid))) {
      $ref = $r['ref'];
      $pid = (int) $r['project_id'];
    }
    $this->db->insert('sthira_log')->fields([
      'request_id' => $rid,
      'ref' => $ref,
      'project_id' => $pid,
      'uid' => $uid,
      'action' => $action,
      'message' => $message,
      'created' => $time ?? time(),
    ])->execute();
  }

  public function logs(?int $rid = NULL, int $limit = 50, ?array $pids = NULL): array {
    $q = $this->db->select('sthira_log', 'l')->fields('l');
    if ($rid !== NULL) {
      $q->condition('l.request_id', $rid);
    }
    if ($pids !== NULL) {
      $or = $q->orConditionGroup()->condition('l.request_id', 0);
      if ($pids) {
        $or->condition('l.project_id', $pids, 'IN');
      }
      $q->condition($or);
    }
    $q->orderBy('l.created', 'DESC')->orderBy('l.id', 'DESC');
    if ($limit) {
      $q->range(0, $limit);
    }
    return $q->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Clears per-request caches (after content is created in bulk).
   */
  /**
   * Forgets loaded data, so the next read sees saved changes.
   */
  public function clearMemo(): void {
    $this->memo = [];
  }

  /**
   * Supplier FAQ entries (published).
   */
  public function faqs(): array {
    $out = [];
    foreach ($this->nodes('faq', [['status', 1]]) as $n) {
      $out[(int) $n->id()] = [
        'id' => (int) $n->id(),
        'question' => $n->label(),
        'answer' => $this->v($n, 'field_answer') ?? '',
        'keywords' => $this->v($n, 'field_keywords') ?? '',
      ];
    }
    return $out;
  }

  public function reset(): void {
    $this->memo = [];
  }

}
