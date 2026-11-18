<?php

namespace Drupal\sthira;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\user\Entity\User;

/**
 * Seeds realistic demo data so every screen has something to show.
 *
 * All companies, people and numbers here are fictional.
 */
class DemoData {

  const PASSWORD = 'demo123';

  /**
   * Public material descriptions (seeded into the material content type).
   */
  const ABOUT_MATERIAL = [
    'OPC 53 Grade Cement' => 'Ordinary Portland Cement that reaches 53 megapascals of strength at 28 days. Used for RCC work such as slabs, beams, columns and foundations. Supplied in 50 kg bags.',
    'TMT Steel Bars Fe500D' => 'Thermo-mechanically treated reinforcement bars (rebar, sariya) with a yield strength of 500 megapascals. The D grade has higher ductility, which helps in earthquake zones. Used to reinforce concrete.',
    'M-Sand' => 'Manufactured sand made by crushing hard granite. A consistent, eco-friendly alternative to river sand for concrete and plaster.',
    'Coarse Aggregate (20 millimetres)' => 'Crushed stone (gravel, jelly, metal) graded to 20 millimetres, mixed with cement and sand to make concrete.',
    'AAC Blocks (600 × 200 × 150 millimetres)' => 'Autoclaved Aerated Concrete blocks: lightweight, well insulated and quicker to lay than clay bricks. Used for internal and external masonry walls.',
    'Red Clay Bricks' => 'Traditional kiln-fired clay bricks for masonry walls and boundary walls.',
    'Fire-Resistant Copper Wire (2.5 square millimetres)' => 'Flame-retardant PVC-insulated copper wire for power and lighting circuits in buildings. Supplied in 90 metre coils.',
    'CPVC Pipe (1 inch, 3 metres long)' => 'Chlorinated PVC pipe for hot and cold water supply lines in bathrooms and kitchens.',
    'Vitrified Tiles (600 × 600 millimetres)' => 'Low-porosity ceramic floor tiles; hard-wearing and stain resistant. Used for flooring in homes, offices and hospitals.',
    'Exterior Emulsion Paint (20 litres)' => 'Weather-resistant water-based paint for outside walls, protects against rain and sun.',
  ];

  /**
   * Supplier FAQ: question, answer, other words people use.
   */
  const FAQ = [
    ['How do I become a supplier?', 'Register as a vendor with your company details, GSTIN, PAN, bank details and documents. Our procurement team verifies you, usually within 1 to 2 working days. Once approved you can add your rates and receive purchase orders.', 'register, registration, join, sign up, onboarding, empanelment, apply, vendor, supply, supplier, sell to you, start supplying, work with you'],
    ['Which documents do I need to register?', 'GST certificate, PAN card, a cancelled cheque for your bank account, and an MSME certificate if you have one. PDF or image files up to 5 MB each.', 'documents, papers, kyc, gst certificate, pan card, msme, cancelled cheque, upload'],
    ['How long does verification take?', 'Usually 1 to 2 working days. You can track the status in your vendor portal, and we email you when it is decided. If something is missing, we tell you what to fix and you can resubmit.', 'verification, approval, pending, status, rejected, how long, waiting'],
    ['When do suppliers get paid?', 'Within 30 days of a matched invoice. We check every invoice against the purchase order and what was actually delivered at site, then release payment for the quantity received.', 'payment, paid, pay, payment terms, credit period, money, invoice, settlement, dues'],
    ['How do you choose a supplier?', 'Every requirement goes out for bids to the approved suppliers of that material. Each bid is scored on price, on-time delivery record and quality rating, and on whether it arrives before the site needs it. The best overall value wins, not just the lowest price. Every order is also approved by the project manager who raised the request before it is placed.', 'choose, selection, criteria, win, lowest price, compare, quotation, bid, bidding, rfq, tender, l1, auction'],
    ['Where do I deliver materials?', 'To the project site named on the purchase order. Our project manager confirms what arrives at site, and that delivery record is what your invoice is matched against.', 'deliver, delivery, dispatch, site address, transport, logistics, unloading'],
    ['Can I update my rates?', 'Yes. Approved suppliers manage their own rates and lead times in the vendor portal, and the latest rates are used when we compare vendors for an order.', 'rates, price list, update price, change rate, lead time, quotation'],
    ['Do you work with small suppliers and MSMEs?', 'Yes. Any GST-registered business can register. MSME suppliers are welcome, and we follow the MSME payment timelines.', 'msme, small business, local supplier, new supplier, startup, trader, dealer'],
    ['Who do I contact about an order?', 'Your purchase order names the procurement officer handling it. You can also see every order and its delivery and payment status in your vendor portal.', 'contact, support, help, phone, email, order status, purchase order, po'],
  ];

  public static function seed(Repository $repo, Connection $db, StateInterface $state): void {
    $uids = self::users($state);
    $now = time();
    $nodes = \Drupal::entityTypeManager()->getStorage('node');
    $make = function (string $type, string $title, array $fields, int $uid = 1) use ($nodes) {
      $node = $nodes->create(['type' => $type, 'title' => $title, 'uid' => $uid, 'status' => 1] + $fields);
      $node->sthira_internal = TRUE;
      $node->save();
      return (int) $node->id();
    };
    $catKey = array_flip(Repository::CATEGORIES);
    $today = strtotime('today');
    $d = fn(int $days) => date('Y-m-d', $today + $days * 86400);

    // Projects.
    $projects = [
      1 => ['P-101', 'Skyline Residency – Tower B', 'Pune', 'Skyline Developers', 42000000, 62, 'Structure · 9th floor slab', '2025-09-01', '2027-06-30'],
      2 => ['P-102', 'Greenfield Logistics Park', 'Bhiwandi', 'Greenfield Warehousing', 28000000, 38, 'Foundation & plinth', '2026-04-15', '2027-08-31'],
      3 => ['P-103', 'City Hospital Annex', 'Kolkata', 'City Care Trust', 19000000, 81, 'Finishing & MEP', '2025-05-10', '2026-12-31'],
    ];
    $about = [
      1 => 'A 22-storey residential apartment tower with 176 flats, a podium car park and a rooftop garden in Hinjewadi, Pune, Maharashtra. RCC frame structure with AAC block walls.',
      2 => 'A grade-A warehousing and logistics park with three large pre-engineered sheds, truck docks and an office block near Mumbai on the Bhiwandi corridor, Thane district, Maharashtra. Heavy industrial flooring and steel structures.',
      3 => 'A 120-bed hospital extension with operation theatres, an ICU wing and outpatient clinics in Salt Lake, Kolkata, West Bengal. Healthcare building now in interior finishing, electrical and plumbing works.',
    ];
    $budgets = [
      1 => ['Steel' => 6000000, 'Finishing' => 2200000, 'Cement' => 1800000, 'Electrical' => 1200000, 'Aggregates' => 900000, 'Bricks & Blocks' => 800000, 'Plumbing' => 700000],
      2 => ['Steel' => 4000000, 'Cement' => 1200000, 'Bricks & Blocks' => 600000, 'Aggregates' => 500000, 'Finishing' => 500000, 'Electrical' => 400000, 'Plumbing' => 300000],
      3 => ['Finishing' => 400000, 'Electrical' => 300000, 'Steel' => 300000, 'Plumbing' => 250000, 'Cement' => 200000],
    ];
    $members = [
      1 => ['pm'],
      2 => ['pm'],
      3 => ['pm2'],
    ];
    $P = [];
    foreach ($projects as $id => $p) {
      $fields = [
        'field_code' => $p[0], 'field_location' => $p[2], 'field_client' => $p[3], 'field_contract_value' => $p[4],
        'field_progress' => $p[5], 'field_phase' => $p[6], 'field_start_date' => $p[7], 'field_end_date' => $p[8],
        'field_description' => $about[$id],
        'field_team' => array_map(fn($n) => ['target_id' => $uids[$n]], $members[$id]),
      ];
      foreach ($budgets[$id] as $cat => $amount) {
        $fields['field_budget_' . $catKey[$cat]] = $amount;
      }
      $P[$id] = $make('project', $p[1], $fields);
    }

    // Catalog.
    $items = [
      1 => ['OPC 53 Grade Cement', 'Cement', 'bags', 420, 'cement,opc,ppc'],
      2 => ['TMT Steel Bars Fe500D', 'Steel', 'tonnes', 63500, 'steel,tmt,rebar,bars,sariya,saria,rods'],
      3 => ['M-Sand', 'Aggregates', 'cubic metres', 1550, 'sand,msand,m-sand'],
      4 => ['Coarse Aggregate (20 millimetres)', 'Aggregates', 'cubic metres', 1350, 'aggregate,aggregates,gravel,jelly,metal,stone'],
      5 => ['AAC Blocks (600 × 200 × 150 millimetres)', 'Bricks & Blocks', 'pieces', 60, 'aac,block,blocks'],
      6 => ['Red Clay Bricks', 'Bricks & Blocks', 'pieces', 9, 'brick,bricks'],
      7 => ['Fire-Resistant Copper Wire (2.5 square millimetres)', 'Electrical', 'coils', 2200, 'wire,wires,cable,cables,copper,wiring'],
      8 => ['CPVC Pipe (1 inch, 3 metres long)', 'Plumbing', 'pieces', 400, 'pipe,pipes,cpvc,pvc,plumbing'],
      9 => ['Vitrified Tiles (600 × 600 millimetres)', 'Finishing', 'square feet', 50, 'tile,tiles,flooring,vitrified'],
      10 => ['Exterior Emulsion Paint (20 litres)', 'Finishing', 'buckets', 6400, 'paint,emulsion,painting'],
    ];
    $I = [];
    foreach ($items as $id => $i) {
      $I[$id] = $make('material', $i[0], ['field_category' => $catKey[$i[1]], 'field_unit' => $i[2], 'field_std_rate' => $i[3], 'field_keywords' => $i[4], 'field_description' => self::ABOUT_MATERIAL[$i[0]] ?? '']);
    }

    // Supplier FAQ.
    foreach (self::FAQ as $f) {
      $make('faq', $f[0], ['field_answer' => $f[1], 'field_keywords' => $f[2]]);
    }

    // Vendors: name, city, categories, rating, on-time %, orders, contract days, contact.
    $vendors = [
      1 => ['Shree Balaji Cement Traders', 'Pune', 'Cement', 4.4, 92, 38, 45, 'Mahesh Joshi'],
      2 => ['Deccan Building Materials', 'Pune', 'Cement, Aggregates', 4.1, 79, 26, 12, 'Sunil Patil'],
      3 => ['Konark Steel Distributors', 'Mumbai', 'Steel', 4.6, 95, 41, 120, 'Ravi Menon'],
      4 => ['Mahalaxmi Iron & Steel', 'Nashik', 'Steel', 3.9, 71, 17, 60, 'Deepak Kulkarni'],
      5 => ['Sai Aggregates & Sand', 'Pune', 'Aggregates', 4.0, 86, 22, 200, 'Ganesh Shinde'],
      6 => ['EcoBlock Industries', 'Bhiwandi', 'Bricks & Blocks', 4.5, 90, 19, 150, 'Farah Khan'],
      7 => ['Brightline Electricals', 'Mumbai', 'Electrical', 4.3, 88, 24, 9, 'Karan Malhotra'],
      8 => ['AquaFlow Pipes & Fittings', 'Thane', 'Plumbing', 4.2, 84, 15, 95, 'Joseph D\'Souza'],
      9 => ['Prism Tiles & Paints', 'Kolkata', 'Finishing', 4.4, 87, 20, 70, 'Ritika Bose'],
      10 => ['National Cement Depot', 'Kolkata', 'Cement, Bricks & Blocks', 3.8, 68, 12, 40, 'Amit Ghosh'],
    ];
    // Each supplier's login.
    $logins = [
      1 => $uids['balaji'], 2 => $uids['deccan'], 3 => $uids['konark'], 4 => $uids['mahalaxmi'], 5 => $uids['sai'],
      6 => $uids['ecoblock'], 7 => $uids['brightline'], 8 => $uids['aquaflow'], 9 => $uids['prism'], 10 => $uids['national'],
    ];
    $V = [];
    foreach ($vendors as $id => $v) {
      $V[$id] = $make('vendor', $v[0], [
        'field_location' => $v[1],
        'field_supplies' => array_map(fn($c) => $catKey[trim($c)], explode(',', $v[2])),
        'field_rating' => $v[3], 'field_on_time' => $v[4], 'field_order_count' => $v[5],
        'field_contract_expiry' => $d($v[6]), 'field_contact' => $v[7],
        'field_gstin' => 'DEMO-GST-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
        'field_phone' => '+91 90000 000' . str_pad((string) $id, 2, '0', STR_PAD_LEFT),
        'field_pan' => 'DEMOP' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) . 'X',
        'field_vendor_status' => 'approved',
      ] + (isset($logins[$id]) ? ['field_vendor_user' => $logins[$id]] : []), $logins[$id] ?? 1);
    }
    // A new supplier who registered yesterday and is waiting for verification.
    $sunrise = $nodes->create([
      'type' => 'vendor',
      'title' => 'Sunrise Steel Traders',
      'uid' => $uids['sunrise'],
      'status' => 1,
      'created' => $now - 26 * 3600,
      'field_location' => 'Kolkata',
      'field_supplies' => ['steel'],
      'field_contact' => 'Kavita Iyer',
      'field_phone' => '+91 90000 00011',
      'field_gstin' => '19DEMOS0011A1Z5',
      'field_pan' => 'DEMOS0011K',
      'field_bank' => 'Demo Bank, A/c 000011112222, IFSC DEMO0000011',
      'field_vendor_status' => 'pending',
      'field_vendor_user' => $uids['sunrise'],
    ]);
    $sunrise->sthira_internal = TRUE;
    $sunrise->save();
    // Rate cards: vendor, item, rate, lead days.
    $quotes = [
      [1, 1, 405, 2], [2, 1, 398, 3], [10, 1, 392, 5],
      [3, 2, 63500, 4], [4, 2, 61200, 6],
      [2, 3, 1550, 2], [5, 3, 1480, 2],
      [2, 4, 1340, 3], [5, 4, 1290, 2],
      [6, 5, 57, 5], [10, 5, 55, 7],
      [6, 6, 9, 3], [10, 6, 8, 4],
      [7, 7, 2150, 3],
      [8, 8, 385, 4],
      [9, 9, 47, 6],
      [9, 10, 6150, 4],
    ];
    foreach ($quotes as $q) {
      $make('vendor_rate', $vendors[$q[0]][0] . ' · ' . $items[$q[1]][0], ['field_vendor' => $V[$q[0]], 'field_material' => $I[$q[1]], 'field_rate' => $q[2], 'field_lead_days' => $q[3]], $logins[$q[0]] ?? 1);
    }

    // Stock at site: project, item, qty, daily use.
    $stock = [
      [1, 1, 130, 45], [1, 2, 14, 1.2], [1, 3, 35, 4], [1, 4, 48, 5], [1, 5, 2200, 300], [1, 7, 12, 1],
      [2, 1, 340, 30], [2, 2, 9, 0.9], [2, 4, 20, 6], [2, 5, 1500, 250],
      [3, 1, 90, 12], [3, 7, 6, 1.5], [3, 8, 30, 6], [3, 9, 900, 120], [3, 10, 14, 2],
    ];
    foreach ($stock as $s) {
      $make('site_stock', $projects[$s[0]][0] . ' · ' . $items[$s[1]][0], ['field_project' => $P[$s[0]], 'field_material' => $I[$s[1]], 'field_stock_qty' => $s[2], 'field_daily_use' => $s[3]]);
    }
    $repo->reset();

    // Requests: project, item, qty, stage, winning vendor, winning price,
    // hours ago (created), options. Every order went out for bids first.
    $requests = [
      // Older paid orders, so the Director's charts show a six-month trend.
      [1, 1, 700, 'paid', 1, 398, 172 * 24, []],
      [3, 1, 250, 'paid', 10, 410, 165 * 24, ['by' => 'pm2']],
      [2, 4, 90, 'paid', 5, 1300, 158 * 24, []],
      [1, 5, 6000, 'paid', 6, 57, 149 * 24, []],
      [2, 6, 20000, 'paid', 6, 8, 140 * 24, []],
      [3, 7, 10, 'paid', 7, 2150, 131 * 24, ['by' => 'pm2']],
      [1, 3, 40, 'paid', 5, 1500, 122 * 24, []],
      [2, 1, 650, 'paid', 2, 392, 113 * 24, []],
      [3, 8, 150, 'paid', 8, 390, 104 * 24, ['by' => 'pm2']],
      [1, 9, 2500, 'paid', 9, 48, 96 * 24, []],
      [1, 7, 14, 'paid', 7, 2180, 89 * 24, []],
      [1, 2, 20, 'paid', 3, 61800, 82 * 24, []],
      [1, 1, 600, 'paid', 1, 400, 75 * 24, []],
      [1, 2, 22, 'paid', 3, 62200, 58 * 24, []],
      [2, 1, 500, 'paid', 2, 395, 52 * 24, []],
      [1, 4, 80, 'paid', 5, 1280, 47 * 24, []],
      [3, 9, 4000, 'paid', 9, 46, 40 * 24, ['by' => 'pm2']],
      [1, 2, 12, 'paid', 3, 61900, 36 * 24, []],
      [2, 5, 6000, 'paid', 6, 56, 33 * 24, []],
      [1, 2, 18, 'paid', 3, 61500, 29 * 24, []],
      [3, 10, 30, 'paid', 9, 6100, 22 * 24, ['by' => 'pm2']],
      [1, 7, 40, 'paid', 7, 2120, 18 * 24, []],
      [2, 2, 15, 'paid', 3, 62400, 14 * 24, []],
      [1, 1, 800, 'paid', 1, 405, 10 * 24, []],
      [3, 8, 120, 'invoiced', 8, 385, 6 * 24, ['received' => 112, 'invoice_qty' => 120, 'invoice_no' => 'AQF/2026/1187', 'by' => 'pm2']],
      [2, 4, 60, 'ordered', 5, 1290, 4 * 24, []],
      [1, 2, 12, 'received', 4, 66950, 3 * 24, ['priority' => 'urgent', 'note' => 'Emergency top-up for the 9th floor column casting. Konark could not deliver in time.']],
      // Waiting for Ananya (who raised it) to approve the winning bid.
      [2, 2, 25, 'order_review', 3, 62900, 40, ['note' => 'Raft foundation, grid C to F.']],
      // Open for bids: Shree Balaji (Mahesh) has not bid yet.
      [2, 1, 450, 'bidding', NULL, NULL, 20, ['note' => 'Plinth beams and ground-floor columns.']],
      // Ready for bids.
      [1, 5, 8000, 'approved', NULL, NULL, 46, ['note' => 'Blockwork for floors 6 to 8.']],
      [3, 7, 15, 'approved', NULL, NULL, 50, ['note' => 'Second-floor ward wiring.', 'by' => 'pm2']],
      [1, 3, 20, 'approved', NULL, NULL, 5, ['raw' => 'Need 20 cubic metres of sand at Skyline by Thursday for plastering']],
    ];
    usort($requests, fn($a, $b) => $b[6] <=> $a[6]);

    $settings = \Drupal::config('sthira.settings');
    $limit = 0;
    $pmFor = [1 => $uids['pm'], 2 => $uids['pm'], 3 => $uids['pm2']];
    $loginFor = $logins;
    $rateCard = [];
    foreach ($quotes as $q) {
      $rateCard[$q[1]][$q[0]] = [$q[2], $q[3]];
    }
    $poCount = 0;
    $flow = ['pm_review', 'approved', 'bidding', 'order_review', 'ordered', 'received', 'invoiced', 'paid'];

    foreach ($requests as [$pid, $itemId, $qty, $stage, $vendorId, $poRate, $hoursAgo, $opt]) {
      $item = $items[$itemId];
      $estAmount = (int) round($qty * $item[3]);
      $poAmount = $poRate ? (int) round($qty * $poRate) : 0;
      $by = isset($opt['by']) ? $uids[$opt['by']] : $pmFor[$pid];
      $t = $now - $hoursAgo * 3600 - 1800;
      $needed = date('Y-m-d', $t + 7 * 86400);
      if (in_array($stage, ['approved', 'bidding', 'order_review'], TRUE)) {
        $needed = $d(6);
      }
      $reached = array_search($stage, $flow, TRUE);

      // Who could bid: approved suppliers of this category.
      $invited = [];
      foreach ($vendors as $vid => $v) {
        if (in_array($item[1], array_map('trim', explode(',', $v[2])), TRUE)) {
          $invited[] = $vid;
        }
      }

      // Event plan, hours after the previous event.
      $plan = ['pm_approved' => 0.01];
      if ($reached >= 2) {
        $plan['bids_invited'] = 3;
        $plan['bids'] = 6;
      }
      if ($reached >= 3) {
        $plan['awarded'] = 18;
      }
      if ($reached >= 4) {
        $plan['order_approved'] = 5;
        $plan['po_issued'] = 0.2;
      }
      foreach (['received' => [5, 60], 'invoice_checked' => [6, 20], 'paid' => [7, 70]] as $ev => [$idx, $gap]) {
        if ($reached >= $idx) {
          $plan[$ev] = $gap;
        }
      }
      $events = ['created' => $t];
      $cursor = $t;
      $i = 0;
      foreach ($plan as $ev => $gap) {
        $cursor += $gap * 3600;
        $cursor = min($cursor, $now - (count($plan) - $i) * 2700);
        $events[$ev] = $cursor;
        $i++;
      }

      $values = [
        'project_id' => $P[$pid],
        'item_id' => $I[$itemId],
        'qty' => $qty,
        'unit' => $item[2],
        'est_rate' => $item[3],
        'est_amount' => $estAmount,
        'needed_by' => $needed,
        'priority' => $opt['priority'] ?? 'normal',
        'note' => $opt['note'] ?? '',
        'raw_text' => $opt['raw'] ?? '',
        'stage' => $stage,
        'created_by' => $by,
        'created' => $t,
        'updated' => max($events),
        'approved_pm' => $pmFor[$pid],
      ];
      if (isset($events['bids_invited'])) {
        $values['invited'] = array_map(fn($vid) => $V[$vid], $invited);
        $values['bids_opened'] = $events['bids_invited'];
      }
      if (isset($events['awarded'])) {
        $values += ['vendor_id' => $V[$vendorId], 'po_rate' => $poRate, 'po_amount' => $poAmount, 'bids_closed' => $events['awarded'] - 1800];
      }
      if (isset($events['order_approved'])) {
        $values['approved_director'] = $by;
      }
      if (isset($events['po_issued'])) {
        $poCount++;
        $values += [
          'po_number' => 'PO-' . date('Y') . '-' . str_pad((string) (100 + $poCount), 4, '0', STR_PAD_LEFT),
          'po_date' => $events['po_issued'],
        ];
      }
      if (isset($events['received'])) {
        $values['received_qty'] = $opt['received'] ?? $qty;
        $values['grn_date'] = $events['received'];
      }
      if (isset($events['invoice_checked'])) {
        $invQty = $opt['invoice_qty'] ?? $values['received_qty'];
        $values['invoice_no'] = $opt['invoice_no'] ?? ('INV-' . strtoupper(substr(md5($pid . $itemId . $hoursAgo), 0, 6)));
        $values['invoice_qty'] = $invQty;
        $values['invoice_amount'] = (int) round($invQty * $poRate);
        $values['match_status'] = abs($invQty - $values['received_qty']) < 0.001 ? 'matched' : 'mismatch';
      }
      if (isset($events['paid'])) {
        $values['paid_amount'] = $values['invoice_amount'];
        $values['paid_date'] = $events['paid'];
      }

      $rid = $repo->createRequest($values);
      $ref = 'MR-' . (1040 + $rid);
      $short = trim(explode('–', $projects[$pid][1])[0]);
      $unit = $item[2];
      $repo->log($rid, $by, 'created', 'Raised ' . $ref . ': ' . Format::qty($qty) . ' ' . $unit . ' of ' . $item[0] . ' for ' . $short . ' (est. ' . Format::inr($estAmount) . ')' . (!empty($opt['raw']) ? ' using AI assist' : '') . '.', $t);
      $repo->log($rid, $pmFor[$pid], 'pm_approved', 'Approved on raising: raised by the project manager.', $events['pm_approved']);

      if (isset($events['bids_invited'])) {
        $names = array_map(fn($vid) => $vendors[$vid][0], $invited);
        $repo->log($rid, $uids['procurement'], 'bids_invited', 'Invited ' . count($names) . ' suppliers to bid: ' . implode(', ', $names) . '.', $events['bids_invited']);
        // Bids. In the open request, suppliers with a login have not bid yet.
        $winnerBid = NULL;
        foreach ($invited as $k => $vid) {
          // The open cement request: Shree Balaji (Mahesh) has not bid yet.
          if ($stage === 'bidding' && $vid === 1) {
            continue;
          }
          [$base, $lead] = $rateCard[$itemId][$vid] ?? [$item[3], 5];
          $seed = crc32($rid . '-' . $vid);
          if ($vid === $vendorId) {
            $rate = $poRate;
          }
          else {
            $rate = $base * (1 + (($seed % 7) - 3) / 100);
            if ($poRate) {
              // Losing bids came in above the winner.
              $rate = max($rate, $poRate * (1.012 + ($seed % 4) / 100));
            }
            $rate = $rate >= 1000 ? round($rate / 50) * 50 : ($rate >= 100 ? round($rate) : round($rate, 1));
          }
          $when = (int) min($events['bids_invited'] + (40 + 35 * $k) * 60, $now - 600);
          $bidId = $repo->saveBid($rid, $V[$vid], $rate, $lead, Bidding::NOTES[$seed % count(Bidding::NOTES)], $loginFor[$vid] ?? 1, $when);
          $repo->log($rid, $loginFor[$vid] ?? 0, 'bid_received', 'Bid from ' . $vendors[$vid][0] . ': ' . Format::inr($rate) . ' per ' . Format::per($unit) . ', delivers in ' . $lead . ' days.', $when);
          if ($vid === $vendorId) {
            $winnerBid = $bidId;
          }
        }
        if (isset($events['awarded'])) {
          $repo->log($rid, 0, 'bids_closed', 'All ' . count($invited) . ' invited suppliers have bid. Bidding closed.', $events['awarded'] - 1800);
          $repo->settleBids($rid, $winnerBid);
          $repo->log($rid, $uids['procurement'], 'awarded', 'Chose ' . $vendors[$vendorId][0] . '\'s bid: ' . Format::inr($poRate) . ' per ' . Format::per($unit) . ', total ' . Format::inr($poAmount) . '. Sent to ' . $repo->person($by)['name'] . ' for approval.', $events['awarded']);
        }
      }
      foreach (['order_approved', 'po_issued', 'received', 'invoice_checked', 'paid'] as $ev) {
        if (!isset($events[$ev])) {
          continue;
        }
        $time = $events[$ev];
        match ($ev) {
          'order_approved' => $repo->log($rid, $by, 'order_approved', 'Approved the order.', $time),
          'po_issued' => $repo->log($rid, $uids['procurement'], 'po_issued', 'Order ' . $values['po_number'] . ' placed with ' . $vendors[$vendorId][0] . ' for ' . Format::inr($poAmount) . '.', $time),
          'received' => $repo->log($rid, $by, 'received', 'Delivery confirmed: ' . Format::qty($values['received_qty']) . ' ' . $unit . ' arrived at site.', $time),
          'invoice_checked' => $repo->log($rid, $uids['procurement'], 'invoice_checked', 'Bill ' . $values['invoice_no'] . ' checked against the order and the delivery: ' . ($values['match_status'] === 'matched' ? 'everything matches.' : 'it does not match.'), $time),
          'paid' => $repo->log($rid, $uids['procurement'], 'paid', 'Paid ' . Format::inr($values['paid_amount']) . ' to ' . $vendors[$vendorId][0] . '.', $time),
        };
      }
    }
    $repo->log(0, $uids['sunrise'], 'vendor_registered', 'Sunrise Steel Traders registered as a vendor. Waiting for procurement to verify.', $now - 26 * 3600);
    $repo->log(0, $uids['admin'], 'settings', 'Approval rule set: the project manager who raised a request approves the winning bid before the order is placed.', $now - 90 * 86400);
  }

  /**
   * Creates (or reuses) the demo accounts.
   */
  public static function users(StateInterface $state): array {
    $people = [
      'director' => ['Rajesh Mehta', 'Director', 'director'],
      'pm' => ['Ananya Sharma', 'Project Manager', 'project_manager'],
      'pm2' => ['Vikram Rao', 'Project Manager', 'project_manager'],
      'procurement' => ['Neha Kapoor', 'Procurement Officer', 'procurement_officer'],
      // Every supplier has a login, so any material can be bid on.
      'balaji' => ['Mahesh Joshi', 'Shree Balaji Cement · cement', 'vendor'],
      'deccan' => ['Sunil Patil', 'Deccan Building Materials · cement, aggregates', 'vendor'],
      'konark' => ['Ravi Menon', 'Konark Steel · steel', 'vendor'],
      'mahalaxmi' => ['Deepak Kulkarni', 'Mahalaxmi Iron & Steel · steel', 'vendor'],
      'sai' => ['Ganesh Shinde', 'Sai Aggregates & Sand · sand, aggregates', 'vendor'],
      'ecoblock' => ['Farah Khan', 'EcoBlock Industries · blocks, bricks', 'vendor'],
      'brightline' => ['Karan Malhotra', 'Brightline Electricals · wire', 'vendor'],
      'aquaflow' => ['Joseph D\'Souza', 'AquaFlow Pipes & Fittings · pipes', 'vendor'],
      'prism' => ['Ritika Bose', 'Prism Tiles & Paints · tiles, paint', 'vendor'],
      'national' => ['Amit Ghosh', 'National Cement Depot · cement, bricks', 'vendor'],
      'sunrise' => ['Kavita Iyer', 'Sunrise Steel Traders · waiting for approval', 'vendor'],
    ];
    // Accounts from earlier versions of the demo (6 and 5 roles).
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    foreach ($storage->loadByProperties(['name' => ['purchase', 'store', 'accounts', 'engineer', 'vendor', 'steel']]) as $old) {
      if (str_ends_with((string) $old->getEmail(), '@example.com')) {
        $old->delete();
      }
    }
    $uids = ['admin' => 1];
    $info = [1 => ['name' => 'Site Admin', 'title' => 'Administrator', 'username' => 'admin']];
    foreach ($people as $username => [$name, $title, $role]) {
      $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $username]);
      $account = $users ? reset($users) : User::create([
        'name' => $username,
        'mail' => $username . '@example.com',
        'status' => 1,
      ]);
      $account->setPassword(self::PASSWORD);
      $account->activate();
      if (!$account->hasRole($role)) {
        $account->addRole($role);
      }
      $account->save();
      $uids[$username] = (int) $account->id();
      $info[(int) $account->id()] = ['name' => $name, 'title' => $title, 'username' => $username, 'group' => $role === 'vendor' ? 'Suppliers' : 'Team'];
    }
    $state->set('sthira.people', $info);
    $state->set('sthira.demo_uids', array_values($uids));
    return $uids;
  }

}
