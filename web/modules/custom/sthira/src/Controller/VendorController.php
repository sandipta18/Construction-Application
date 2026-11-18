<?php

namespace Drupal\sthira\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sthira\Form\BidForm;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;
use Drupal\sthira\Workflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The vendor portal: a vendor's own company, rates and purchase orders.
 */
class VendorController extends ControllerBase {

  public function __construct(protected Repository $repo) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('sthira.repository'));
  }

  public function portal() {
    $uid = (int) $this->currentUser()->id();
    $v = $this->repo->vendorForUser($uid);
    if (!$v) {
      return ['#markup' => '<div class="page"><p class="empty">Your login is not linked to a vendor company yet. Please contact procurement.</p></div>'];
    }
    $node = $this->repo->vendorNode($v['id']);
    $back = Url::fromRoute('sthira.vendor_portal')->toString();
    $approved = $v['status'] === 'approved';

    $rates = [];
    foreach ($this->repo->vendorRates($v['id']) as $r) {
      $rates[] = [
        'item' => $r['item'],
        'rate' => Format::inr($r['rate']) . '/' . Format::per($r['unit']),
        'lead' => $r['lead_days'] . ' days',
        'updated' => Format::ago($r['updated']),
        'edit' => Url::fromRoute('entity.node.edit_form', ['node' => $r['id']], ['query' => ['destination' => $back]])->toString(),
        'delete' => Url::fromRoute('entity.node.delete_form', ['node' => $r['id']], ['query' => ['destination' => $back]])->toString(),
      ];
    }

    $items = $this->repo->items();
    $projects = $this->repo->projects();

    // Requests this supplier was invited to bid on.
    $open = [];
    $history = [];
    foreach ($this->repo->invitedRequests($v['id']) as $r) {
      $item = $items[$r['item_id']] ?? ['name' => '?', 'unit' => ''];
      $project = $projects[$r['project_id']] ?? ['name' => '', 'location' => ''];
      $mine = $this->repo->bidFor($r['id'], $v['id']);
      $row = [
        'title' => Format::qty($r['qty']) . ' ' . $r['unit'] . ' · ' . $item['name'],
        'where' => $project['name'] . ', ' . $project['location'],
        'needed' => Format::day($r['needed_by']),
        'ref' => $r['ref'],
        'my_bid' => $mine ? Format::inr($mine['rate']) . ' per ' . Format::per($r['unit']) . ' · ' . $mine['lead_days'] . ' days · total ' . Format::inr($mine['rate'] * (float) $r['qty']) : NULL,
      ];
      if ($r['stage'] === 'bidding' && empty($r['bids_closed']) && $approved) {
        $row['form'] = $this->formBuilder()->getForm(BidForm::create(\Drupal::getContainer())->setRequestId($r['id']));
        $open[] = $row;
      }
      elseif ($mine) {
        $row['result'] = match ($mine['status']) {
          'won' => 'Won',
          'lost' => 'Not selected',
          default => 'Under review',
        };
        $row['tone'] = ['won' => 'ok', 'lost' => 'neutral'][$mine['status']] ?? 'info';
        $history[] = $row;
      }
    }

    $orders = [];
    foreach ($this->repo->requestsForVendor($v['id']) as $r) {
      if (empty($r['po_number'])) {
        continue;
      }
      $stage = Workflow::stage($r['stage']);
      $orders[] = [
        'po' => $r['po_number'],
        'title' => Format::qty($r['qty']) . ' ' . $r['unit'] . ' · ' . ($items[$r['item_id']]['name'] ?? ''),
        'amount' => Format::short($r['po_amount']),
        'date' => Format::date((int) $r['po_date']),
        'stage' => match ($r['stage']) {
          'ordered' => 'To deliver',
          'received' => 'Delivered',
          'invoiced' => 'Invoice under check',
          'paid' => 'Paid',
          default => $stage['label'],
        },
        'tone' => match ($r['stage']) {
          'ordered' => 'warn',
          'paid' => 'ok',
          default => 'info',
        },
      ];
    }

    return [
      '#theme' => 'sthira_vendor_portal',
      '#v' => $v + [
        'supplies' => $v['categories'],
        'email' => $this->currentUser()->getEmail(),
        'status_label' => ['pending' => 'Pending verification', 'approved' => 'Approved', 'rejected' => 'Needs changes'][$v['status']] ?? $v['status'],
        'status_tone' => ['pending' => 'warn', 'approved' => 'ok', 'rejected' => 'bad'][$v['status']] ?? 'neutral',
        'rating' => number_format($v['rating'], 1),
      ],
      '#approved' => $approved,
      '#rates' => $rates,
      '#open_bids' => $open,
      '#bid_history' => array_slice($history, 0, 8),
      '#orders' => $orders,
      '#urls' => [
        'edit' => $node && $node->access('update') ? Url::fromRoute('entity.node.edit_form', ['node' => $v['id']], ['query' => ['destination' => $back]])->toString() : NULL,
        'add_rate' => $approved ? Url::fromRoute('node.add', ['node_type' => 'vendor_rate'], ['query' => ['destination' => $back]])->toString() : NULL,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
