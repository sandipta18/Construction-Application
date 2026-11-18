<?php

namespace Drupal\sthira;

/**
 * Bids: invite suppliers, collect their prices, close bidding, choose a
 * winner, get the approval of the PM who raised the request, place the order.
 *
 * Every bid comes from a supplier, in the supplier portal. Bidding closes when
 * every invited supplier has bid, or when Procurement closes it early.
 */
class Bidding {

  /**
   * Notes a seeded (past) bid may carry.
   */
  const NOTES = [
    'Price includes delivery to site.',
    'Can supply from stock.',
    'Price valid for 7 days.',
    'Includes unloading at site.',
    'Test certificate supplied with the delivery.',
  ];

  public function __construct(
    protected Repository $repo,
    protected Workflow $workflow,
    protected Assistant $assistant,
  ) {}

  /**
   * Approved suppliers who could bid on a request (they supply its category).
   */
  public function candidates(array $r): array {
    $item = $this->repo->items()[$r['item_id']] ?? NULL;
    return $item ? $this->repo->vendorsForCategory($item['category']) : [];
  }

  /**
   * Sends the request out for bids.
   *
   * @return array
   *   ['invited' => vendor names]
   */
  public function invite(int $id, array $vendorIds, int $uid, ?int $time = NULL): array {
    $r = $this->repo->request($id);
    $time = $time ?? time();
    $vendors = $this->repo->vendors();
    $vendorIds = array_values(array_filter(array_map('intval', $vendorIds), fn($v) => isset($vendors[$v])));
    $this->repo->updateRequest($id, ['stage' => 'bidding', 'invited' => $vendorIds, 'bids_opened' => $time, 'updated' => $time]);
    $names = array_map(fn($v) => $vendors[$v]['name'], $vendorIds);
    $this->repo->log($id, $uid, 'bids_invited', 'Invited ' . count($names) . ' suppliers to bid: ' . implode(', ', $names) . '.', $time);
    $this->repo->log($id, 0, 'notified', 'Bid invitations sent to ' . implode(', ', $names) . ' on WhatsApp and email (demo).', $time + 1);
    return ['invited' => $names];
  }

  /**
   * Invited suppliers who have not bid yet (names keyed by vendor id).
   */
  public function pending(array $r): array {
    $vendors = $this->repo->vendors();
    $bidders = array_column($this->repo->bids((int) $r['id']), 'vendor_id');
    $out = [];
    foreach (array_diff($r['invited'], $bidders) as $vid) {
      $out[$vid] = $vendors[$vid]['name'] ?? '';
    }
    return $out;
  }

  public function isOpen(array $r): bool {
    return $r['stage'] === 'bidding' && empty($r['bids_closed']);
  }

  /**
   * Closes bidding, so Procurement can choose the winner.
   */
  public function close(int $id, int $uid, bool $early = FALSE): void {
    $r = $this->repo->request($id);
    $n = count($this->repo->bids($id));
    $this->repo->updateRequest($id, ['bids_closed' => time()]);
    $this->repo->log($id, $uid, 'bids_closed', $early
      ? 'Bidding closed early by Procurement with ' . $n . ' of ' . count($r['invited']) . ' bids. Not received from: ' . implode(', ', $this->pending($r)) . '.'
      : 'All ' . $n . ' invited suppliers have bid. Bidding closed.');
  }

  /**
   * A supplier's own bid from the supplier portal.
   */
  public function submit(int $id, array $vendor, float $rate, int $leadDays, string $note, int $uid): void {
    $r = $this->repo->request($id);
    $again = (bool) $this->repo->bidFor($id, $vendor['id']);
    $this->repo->saveBid($id, $vendor['id'], $rate, $leadDays, $note, $uid);
    $this->repo->log($id, $uid, 'bid_received', ($again ? 'Updated bid' : 'Bid') . ' from ' . $vendor['name'] . ': ' . Format::inr($rate) . ' per ' . Format::per($r['unit']) . ', delivers in ' . $leadDays . ' days.');
    // Everyone has bid: close bidding and tell Procurement.
    if (!$this->pending($this->repo->request($id))) {
      $this->close($id, 0);
      $people = array_map(fn($u) => $this->repo->person($u)['name'], $this->repo->usersWithRole('procurement_officer'));
      $this->repo->log($id, 0, 'notified', 'WhatsApp alert sent to ' . implode(', ', $people) . ': all bids are in, ready to choose the winner (demo).');
    }
  }

  /**
   * Chooses the winning bid. It then waits for the PM's approval.
   *
   * @return string
   *   The new stage: 'order_review'.
   */
  public function award(int $id, int $bidId, int $uid, ?int $time = NULL): string {
    $time = $time ?? time();
    $r = $this->repo->request($id);
    $bids = $this->repo->bids($id);
    $bid = $bids[$bidId];
    $vendor = $this->repo->vendor($bid['vendor_id']);
    $amount = (int) round($bid['rate'] * (float) $r['qty']);
    $ranked = $this->assistant->rankBids($r, $bids);
    $followed = $ranked && $ranked[0]['bid_id'] === $bidId;

    $this->repo->updateRequest($id, ['vendor_id' => $vendor['id'], 'po_rate' => (int) round($bid['rate']), 'po_amount' => $amount, 'updated' => $time]);
    $this->repo->settleBids($id, $bidId);
    $this->repo->log($id, $uid, 'awarded', 'Chose ' . $vendor['name'] . '\'s bid: ' . Format::inr($bid['rate']) . ' per ' . Format::per($r['unit']) . ', delivers in ' . $bid['lead_days'] . ' days, total ' . Format::inr($amount) . '.' . ($followed ? ' The suggested best bid.' : ' Chose differently from the suggested ' . $ranked[0]['name'] . '.'), $time);

    $this->repo->updateRequest($id, ['stage' => 'order_review', 'updated' => $time]);
    return 'order_review';
  }

  /**
   * The PM who raised the request approves the order; it is placed at once.
   */
  public function approveOrder(int $id, int $uid, string $comment = '', ?int $time = NULL): void {
    $time = $time ?? time();
    $this->repo->updateRequest($id, ['approved_director' => $uid, 'updated' => $time]);
    $this->repo->log($id, $uid, 'order_approved', 'Approved the order' . ($comment ? ': ' . $comment : '.'), $time);
    $this->issueOrder($id, $uid, $time + 60);
  }

  /**
   * The PM rejects the choice and sends it back to Procurement.
   */
  public function sendBack(int $id, int $uid, string $reason): void {
    $this->repo->updateRequest($id, ['stage' => 'bidding', 'vendor_id' => NULL, 'po_rate' => NULL, 'po_amount' => NULL]);
    $this->repo->settleBids($id, NULL);
    $this->repo->log($id, $uid, 'sent_back', 'Rejected the winning bid and sent it back to Procurement: ' . $reason);
  }

  /**
   * Places the order with the chosen supplier.
   */
  public function issueOrder(int $id, int $uid, ?int $time = NULL): string {
    $time = $time ?? time();
    $r = $this->repo->request($id);
    $vendor = $this->repo->vendor((int) $r['vendor_id']);
    $po = $this->repo->nextPoNumber();
    $this->repo->updateRequest($id, ['stage' => 'ordered', 'po_number' => $po, 'po_date' => min($time, time()), 'updated' => min($time, time())]);
    $this->repo->log($id, $uid, 'po_issued', 'Order ' . $po . ' placed with ' . $vendor['name'] . ' for ' . Format::inr($r['po_amount']) . '.', min($time, time()));
    $this->repo->log($id, 0, 'notified', 'Order ' . $po . ' sent to ' . $vendor['name'] . ' on email and WhatsApp (demo).', min($time, time()));
    return $po;
  }

}
