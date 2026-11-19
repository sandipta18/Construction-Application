<?php

namespace Drupal\sthira\Data;

use Drupal\Core\Database\Connection;
use Drupal\sthira\Repository;
use Drupal\sthira\Workflow;

/**
 * Plain SQL views over Drupal's field tables, for "Ask the data".
 *
 * Drupal keeps every field in its own table (node__field_qty, ...), so a
 * question about requests needs a dozen joins. These views do the joins once
 * and give each fact a readable column name, so the queries people read stay
 * short: SELECT project, SUM(order_value) FROM v_requests GROUP BY project.
 *
 * The views are real database objects: they also work in phpMyAdmin or
 * `ddev mysql`. They are rebuilt on install and dropped on uninstall.
 */
class DataViews {

  public function __construct(protected Connection $db) {}

  /**
   * Every view, with its columns and what they mean (shown on the page and
   * given to the local AI model).
   */
  public static function catalog(): array {
    return [
      'v_requests' => [
        'about' => 'One row per material request, with its order, delivery, bill and payment.',
        'columns' => [
          'id' => 'request number in the system',
          'ref' => 'request reference, e.g. MR-1050',
          'project' => 'project name',
          'project_code' => 'project code, e.g. P-101',
          'city' => 'project city',
          'material' => 'material name',
          'category' => 'material type: Steel, Cement, Aggregates, Bricks & Blocks, Electrical, Plumbing, Finishing',
          'quantity' => 'quantity requested',
          'unit' => 'unit, e.g. tonnes, bags',
          'stage' => 'stage key: pm_review, approved, bidding, order_review, ordered, received, invoiced, paid, rejected',
          'stage_label' => 'stage in words, e.g. Collecting bids',
          'priority' => 'normal or urgent',
          'needed_by' => 'date needed at site (YYYY-MM-DD)',
          'raised_by' => 'username of the person who raised it',
          'raised_on' => 'date raised (YYYY-MM-DD)',
          'raised_month' => 'month raised (YYYY-MM)',
          'estimate' => 'estimated cost in rupees',
          'supplier' => 'winning supplier (empty until chosen)',
          'order_rate' => 'agreed rate per unit in rupees',
          'order_value' => 'order value in rupees (empty until a winner is chosen)',
          'po_number' => 'purchase order number',
          'ordered_on' => 'date the order was placed',
          'order_month' => 'month the order was placed (YYYY-MM)',
          'received_qty' => 'quantity delivered',
          'delivered_on' => 'date delivery was confirmed',
          'bill_amount' => 'supplier bill amount in rupees',
          'bill_check' => 'matched or mismatch',
          'paid_amount' => 'amount paid in rupees',
          'paid_on' => 'date paid',
          'paid_month' => 'month paid (YYYY-MM)',
        ],
      ],
      'v_people' => [
        'about' => 'One row per login: team members and supplier accounts, with their role.',
        'columns' => [
          'id' => 'user number',
          'name' => 'full name',
          'username' => 'login name',
          'role' => 'the login\'s role, e.g. Director, Project Manager, Procurement Officer, Supplier, Administrator',
          'kind' => 'Employee (our own staff), Supplier (a supplier login) or Admin',
          'title' => 'job title or company and what they sell',
          'company' => 'supplier company (suppliers only)',
          'active' => '1 if the login is active',
          'joined_on' => 'date the account was created',
        ],
      ],
      'v_project_team' => [
        'about' => 'One row per project and project manager on its team (who runs which project).',
        'columns' => [
          'project' => 'project name',
          'code' => 'project code, e.g. P-101',
          'city' => 'project city',
          'manager' => 'full name of the project manager',
          'username' => 'login name of the project manager',
        ],
      ],
      'v_bids' => [
        'about' => 'One row per bid a supplier sent for a request.',
        'columns' => [
          'id' => 'bid number',
          'request_ref' => 'request reference, e.g. MR-1050',
          'project' => 'project name',
          'material' => 'material name',
          'supplier' => 'supplier name',
          'rate' => 'price per unit in rupees',
          'lead_days' => 'days to deliver',
          'status' => 'submitted, won or lost',
          'bid_on' => 'date of the bid',
        ],
      ],
      'v_projects' => [
        'about' => 'One row per project.',
        'columns' => [
          'id' => 'project number in the system',
          'code' => 'project code, e.g. P-101',
          'name' => 'project name',
          'city' => 'city',
          'client' => 'client',
          'phase' => 'current stage of work',
          'progress_pct' => 'work done, 0 to 100',
          'start_date' => 'start date',
          'end_date' => 'expected finish date',
          'contract_value' => 'contract value in rupees',
          'material_budget' => 'total material budget in rupees',
        ],
      ],
      'v_suppliers' => [
        'about' => 'One row per supplier company.',
        'columns' => [
          'id' => 'supplier number in the system',
          'name' => 'company name',
          'city' => 'city',
          'contact' => 'contact person',
          'rating' => 'rating out of 5',
          'on_time_pct' => 'share of deliveries on time, 0 to 100',
          'past_orders' => 'orders delivered before this system',
          'status' => 'approved, pending or rejected',
          'login' => 'their username',
        ],
      ],
      'v_supplier_categories' => [
        'about' => 'Which material types each supplier sells (one row per pair).',
        'columns' => [
          'supplier' => 'supplier name',
          'category' => 'material type',
        ],
      ],
      'v_materials' => [
        'about' => 'One row per material we buy.',
        'columns' => [
          'id' => 'material number in the system',
          'name' => 'material name',
          'category' => 'material type',
          'unit' => 'unit',
          'standard_rate' => 'usual price per unit in rupees',
        ],
      ],
      'v_activity' => [
        'about' => 'Audit trail: one row per action anyone took.',
        'columns' => [
          'id' => 'entry number',
          'happened_on' => 'date (YYYY-MM-DD)',
          'happened_at' => 'date and time',
          'username' => 'who did it (empty for the system)',
          'action' => 'action key, e.g. created, bid_received, awarded, order_approved, received, paid',
          'message' => 'what happened, in words',
          'request_ref' => 'request reference, when it is about a request',
        ],
      ],
    ];
  }

  /**
   * Drops and recreates every view.
   */
  public function rebuild(): void {
    $this->drop();
    $definitions = $this->definitions();
    foreach ($definitions as $name => $sql) {
      $this->db->query('CREATE VIEW ' . $name . ' AS ' . $sql);
    }
    \Drupal::state()->set('sthira.data_views_sig', md5(serialize($definitions)));
  }

  /**
   * Role machine name => readable name, read from the site's roles.
   */
  public function roleLabels(): array {
    $labels = [];
    try {
      foreach (\Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple() as $id => $role) {
        if (!in_array($id, ['anonymous', 'authenticated'], TRUE)) {
          $labels[$id] = $role->label();
        }
      }
    }
    catch (\Throwable $e) {
    }
    // Plain words for the two roles whose names are technical.
    $labels['vendor'] = 'Supplier';
    $labels['administrator'] = 'Administrator';
    return $labels;
  }

  public function drop(): void {
    foreach (array_reverse(array_keys(self::catalog())) as $name) {
      $this->db->query('DROP VIEW IF EXISTS ' . $name);
    }
  }

  /**
   * True when every view is there (rebuilds them if not).
   */
  public function ensure(): void {
    // Roles or view code changed since the views were made: make them again.
    if (\Drupal::state()->get('sthira.data_views_sig') !== md5(serialize($this->definitions()))) {
      $this->rebuild();
      return;
    }
    foreach (array_keys(self::catalog()) as $name) {
      try {
        $this->db->query('SELECT 1 FROM ' . $name . ' WHERE 1 = 0');
      }
      catch (\Throwable $e) {
        $this->rebuild();
        return;
      }
    }
  }

  public function isMysql(): bool {
    return $this->db->databaseType() === 'mysql';
  }

  /**
   * Unix timestamp column to a date / month / date-time, per database.
   */
  protected function date(string $col): string {
    return $this->isMysql() ? "DATE(FROM_UNIXTIME($col))" : "date($col, 'unixepoch')";
  }

  protected function month(string $col): string {
    return $this->isMysql() ? "DATE_FORMAT(FROM_UNIXTIME($col), '%Y-%m')" : "strftime('%Y-%m', $col, 'unixepoch')";
  }

  protected function datetime(string $col): string {
    return $this->isMysql() ? "FROM_UNIXTIME($col)" : "datetime($col, 'unixepoch')";
  }

  /**
   * CASE expression turning a key column into its label.
   */
  protected function labels(string $col, array $map): string {
    $parts = [];
    foreach ($map as $key => $label) {
      $parts[] = "WHEN '" . addslashes((string) $key) . "' THEN '" . str_replace("'", "''", (string) $label) . "'";
    }
    return "(CASE $col " . implode(' ', $parts) . " ELSE $col END)";
  }

  /**
   * LEFT JOIN of one field table onto an entity alias.
   */
  protected function field(string $field, string $alias, string $on): string {
    return "LEFT JOIN {node__field_$field} $alias ON $alias.entity_id = $on.nid AND $alias.deleted = 0";
  }

  protected function definitions(): array {
    $cat = Repository::CATEGORIES;
    $stages = array_map(fn($s) => $s['long'], Workflow::STAGES);
    $f = fn($field, $alias, $on = 'n') => $this->field($field, $alias, $on);

    $requests = "SELECT n.nid AS id, ref.field_ref_value AS ref, p.title AS project, pc.field_code_value AS project_code, pl.field_location_value AS city,
      m.title AS material, " . $this->labels('mc.field_category_value', $cat) . " AS category,
      q.field_qty_value AS quantity, un.field_unit_value AS unit,
      st.field_stage_value AS stage, " . $this->labels('st.field_stage_value', $stages) . " AS stage_label,
      pr.field_priority_value AS priority, nb.field_needed_by_value AS needed_by,
      ru.name AS raised_by, " . $this->date('n.created') . " AS raised_on, " . $this->month('n.created') . " AS raised_month,
      ea.field_est_amount_value AS estimate, v.title AS supplier,
      prt.field_po_rate_value AS order_rate, pa.field_po_amount_value AS order_value, pn.field_po_number_value AS po_number,
      " . $this->date('pd.field_po_date_value') . " AS ordered_on, " . $this->month('pd.field_po_date_value') . " AS order_month,
      rq.field_received_qty_value AS received_qty, " . $this->date('gd.field_grn_date_value') . " AS delivered_on,
      ia.field_invoice_amount_value AS bill_amount, ms.field_match_status_value AS bill_check,
      pam.field_paid_amount_value AS paid_amount, " . $this->date('pdt.field_paid_date_value') . " AS paid_on, " . $this->month('pdt.field_paid_date_value') . " AS paid_month
    FROM {node_field_data} n
    " . $f('ref', 'ref') . "
    " . $f('project', 'fp') . "
    LEFT JOIN {node_field_data} p ON p.nid = fp.field_project_target_id
    " . $f('code', 'pc', 'p') . "
    " . $f('location', 'pl', 'p') . "
    " . $f('material', 'fm') . "
    LEFT JOIN {node_field_data} m ON m.nid = fm.field_material_target_id
    " . $f('category', 'mc', 'm') . "
    " . $f('qty', 'q') . "
    " . $f('unit', 'un') . "
    " . $f('stage', 'st') . "
    " . $f('priority', 'pr') . "
    " . $f('needed_by', 'nb') . "
    LEFT JOIN {users_field_data} ru ON ru.uid = n.uid
    " . $f('est_amount', 'ea') . "
    " . $f('vendor', 'fv') . "
    LEFT JOIN {node_field_data} v ON v.nid = fv.field_vendor_target_id
    " . $f('po_rate', 'prt') . "
    " . $f('po_amount', 'pa') . "
    " . $f('po_number', 'pn') . "
    " . $f('po_date', 'pd') . "
    " . $f('received_qty', 'rq') . "
    " . $f('grn_date', 'gd') . "
    " . $f('invoice_amount', 'ia') . "
    " . $f('match_status', 'ms') . "
    " . $f('paid_amount', 'pam') . "
    " . $f('paid_date', 'pdt') . "
    WHERE n.type = 'material_request'";

    $bids = "SELECT n.nid AS id, ref.field_ref_value AS request_ref, p.title AS project, m.title AS material, v.title AS supplier,
      rt.field_rate_value AS rate, ld.field_lead_days_value AS lead_days, bs.field_bid_status_value AS status, " . $this->date('n.created') . " AS bid_on
    FROM {node_field_data} n
    " . $f('request', 'fr') . "
    LEFT JOIN {node_field_data} r ON r.nid = fr.field_request_target_id
    " . $f('ref', 'ref', 'r') . "
    " . $f('project', 'fp', 'r') . "
    LEFT JOIN {node_field_data} p ON p.nid = fp.field_project_target_id
    " . $f('material', 'fm', 'r') . "
    LEFT JOIN {node_field_data} m ON m.nid = fm.field_material_target_id
    " . $f('vendor', 'fv') . "
    LEFT JOIN {node_field_data} v ON v.nid = fv.field_vendor_target_id
    " . $f('rate', 'rt') . "
    " . $f('lead_days', 'ld') . "
    " . $f('bid_status', 'bs') . "
    WHERE n.type = 'bid'";

    $budget = implode(' + ', array_map(fn($k) => "COALESCE(b_$k.field_budget_{$k}_value, 0)", array_keys($cat)));
    $budgetJoins = implode("\n    ", array_map(fn($k) => $f("budget_$k", "b_$k"), array_keys($cat)));
    $projects = "SELECT n.nid AS id, c.field_code_value AS code, n.title AS name, l.field_location_value AS city, cl.field_client_value AS client,
      ph.field_phase_value AS phase, pg.field_progress_value AS progress_pct, sd.field_start_date_value AS start_date, ed.field_end_date_value AS end_date,
      cv.field_contract_value_value AS contract_value, ($budget) AS material_budget
    FROM {node_field_data} n
    " . $f('code', 'c') . "
    " . $f('location', 'l') . "
    " . $f('client', 'cl') . "
    " . $f('phase', 'ph') . "
    " . $f('progress', 'pg') . "
    " . $f('start_date', 'sd') . "
    " . $f('end_date', 'ed') . "
    " . $f('contract_value', 'cv') . "
    $budgetJoins
    WHERE n.type = 'project'";

    $suppliers = "SELECT n.nid AS id, n.title AS name, l.field_location_value AS city, ct.field_contact_value AS contact,
      rt.field_rating_value AS rating, ot.field_on_time_value AS on_time_pct, oc.field_order_count_value AS past_orders,
      vs.field_vendor_status_value AS status, u.name AS login
    FROM {node_field_data} n
    " . $f('location', 'l') . "
    " . $f('contact', 'ct') . "
    " . $f('rating', 'rt') . "
    " . $f('on_time', 'ot') . "
    " . $f('order_count', 'oc') . "
    " . $f('vendor_status', 'vs') . "
    " . $f('vendor_user', 'vu') . "
    LEFT JOIN {users_field_data} u ON u.uid = vu.field_vendor_user_target_id
    WHERE n.type = 'vendor'";

    $supplierCats = "SELECT n.title AS supplier, " . $this->labels('s.field_supplies_value', $cat) . " AS category
    FROM {node_field_data} n
    JOIN {node__field_supplies} s ON s.entity_id = n.nid AND s.deleted = 0
    WHERE n.type = 'vendor'";

    $materials = "SELECT n.nid AS id, n.title AS name, " . $this->labels('c.field_category_value', $cat) . " AS category,
      un.field_unit_value AS unit, sr.field_std_rate_value AS standard_rate
    FROM {node_field_data} n
    " . $f('category', 'c') . "
    " . $f('unit', 'un') . "
    " . $f('std_rate', 'sr') . "
    WHERE n.type = 'material'";

    // Role names come from the site's own roles, so a new role shows up here
    // by itself. Everyone who is not a supplier login or an admin counts as
    // an employee.
    $roles = $this->roleLabels();
    $kind = "(CASE WHEN r.roles_target_id IS NULL THEN NULL WHEN r.roles_target_id = 'vendor' THEN 'Supplier' WHEN r.roles_target_id = 'administrator' THEN 'Admin' ELSE 'Employee' END)";
    $people = "SELECT u.uid AS id, COALESCE(NULLIF(p.full_name, ''), u.name) AS name, u.name AS username,
      " . $this->labels('r.roles_target_id', $roles) . " AS role, $kind AS kind, p.title AS title, v.title AS company,
      u.status AS active, " . $this->date('u.created') . " AS joined_on
    FROM {users_field_data} u
    LEFT JOIN {user__roles} r ON r.entity_id = u.uid AND r.deleted = 0
    LEFT JOIN {sthira_people} p ON p.uid = u.uid
    LEFT JOIN {node__field_vendor_user} vu ON vu.field_vendor_user_target_id = u.uid AND vu.deleted = 0
    LEFT JOIN {node_field_data} v ON v.nid = vu.entity_id
    WHERE u.uid > 0";

    $team = "SELECT pr.title AS project, c.field_code_value AS code, l.field_location_value AS city,
      COALESCE(NULLIF(p.full_name, ''), u.name) AS manager, u.name AS username
    FROM {node_field_data} pr
    JOIN {node__field_team} t ON t.entity_id = pr.nid AND t.deleted = 0
    JOIN {users_field_data} u ON u.uid = t.field_team_target_id
    LEFT JOIN {sthira_people} p ON p.uid = u.uid
    " . $f('code', 'c', 'pr') . "
    " . $f('location', 'l', 'pr') . "
    WHERE pr.type = 'project'";

    $activity = "SELECT l.id AS id, " . $this->date('l.created') . " AS happened_on, " . $this->datetime('l.created') . " AS happened_at,
      u.name AS username, l.action AS action, l.message AS message, l.ref AS request_ref
    FROM {sthira_log} l
    LEFT JOIN {users_field_data} u ON u.uid = l.uid AND l.uid > 0";

    return [
      'v_requests' => $requests,
      'v_bids' => $bids,
      'v_projects' => $projects,
      'v_suppliers' => $suppliers,
      'v_supplier_categories' => $supplierCats,
      'v_materials' => $materials,
      'v_activity' => $activity,
      'v_people' => $people,
      'v_project_team' => $team,
    ];
  }

}
