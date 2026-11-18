<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sthira\Assistant;
use Drupal\sthira\Bidding;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;
use Drupal\sthira\Workflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The action panel on a request: approve, invite bids, choose a winner,
 * approve big orders, confirm delivery, check the bill, pay.
 */
class StageForm extends FormBase {

  public function __construct(
    protected Repository $repo,
    protected Workflow $workflow,
    protected Assistant $assistant,
    protected Bidding $bidding,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sthira.repository'),
      $container->get('sthira.workflow'),
      $container->get('sthira.assistant'),
      $container->get('sthira.bidding'),
    );
  }

  public function getFormId() {
    return 'sthira_stage_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $id = NULL) {
    $r = $this->repo->request((int) $id);
    $form['#cache']['max-age'] = 0;
    if (!$r || !$this->workflow->canAct($r, $this->currentUser())) {
      return $form;
    }
    $form_state->set('request_id', (int) $id);
    $form['#attributes']['class'][] = 'stage-form';
    $unit = $r['unit'];

    switch ($r['stage']) {
      case 'pm_review':
        $form['intro'] = $this->intro('Approve this request?', 'Once approved, Procurement asks suppliers for bids.');
        $form['comment'] = [
          '#type' => 'textarea',
          '#title' => 'Reason',
          '#rows' => 2,
          '#placeholder' => 'Reason (only needed if you reject)',
          '#title_display' => 'invisible',
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['approve'] = [
          '#type' => 'submit',
          '#value' => 'Approve',
          '#name' => 'approve',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
        ];
        $form['actions']['reject'] = [
          '#type' => 'submit',
          '#value' => 'Reject',
          '#name' => 'reject',
          '#attributes' => ['class' => ['btn', 'btn-ghost', 'btn-danger']],
        ];
        break;

      case 'approved':
        $candidates = $this->bidding->candidates($r);
        $item = $this->repo->items()[$r['item_id']] ?? ['category' => 'this material'];
        $form['intro'] = $this->intro('Invite bids', $candidates
          ? 'We will send this request to the approved ' . strtolower($item['category']) . ' suppliers below. Each replies with a price and a delivery time.'
          : 'No approved supplier sells ' . strtolower($item['category']) . ' yet. Approve one under Suppliers first.');
        $options = [];
        foreach ($candidates as $v) {
          $options[$v['id']] = $v['name'] . ' · ' . $v['city'];
        }
        $form['vendors'] = [
          '#type' => 'checkboxes',
          '#title' => 'Suppliers to invite',
          '#options' => $options,
          '#default_value' => array_keys($options),
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['invite'] = [
          '#type' => 'submit',
          '#value' => 'Invite bids',
          '#name' => 'invite',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
          '#access' => (bool) $options,
        ];
        break;

      case 'bidding':
        $bids = $this->repo->bids((int) $id);
        $ranked = $this->assistant->rankBids($r, $bids);
        $pending = $this->bidding->pending($r);
        if ($this->bidding->isOpen($r)) {
          // Still collecting: suppliers bid first, the winner comes later.
          $form['intro'] = $this->intro('Waiting for bids', count($bids) . ' of ' . count($r['invited']) . ' suppliers have bid. Waiting for: ' . implode(', ', $pending) . '. Bidding closes by itself when everyone has bid.');
          if ($bids) {
            $form['actions'] = ['#type' => 'actions'];
            $form['actions']['close'] = [
              '#type' => 'submit',
              '#value' => 'Close bidding now',
              '#name' => 'close',
              '#attributes' => ['class' => ['btn', 'btn-ghost']],
            ];
          }
          break;
        }
        if (!$ranked) {
          $form['intro'] = $this->intro('No bids received', 'Bidding closed without any bids.');
          break;
        }
        $waiting = $pending;
        $text = 'Bidding is closed with ' . count($ranked) . ' of ' . count($r['invited']) . ' bids. The assistant ranked them and picked the best overall value; you can choose another.';
        $text .= ' The Director approves your choice before the order is placed.';
        // If the Director sent the last choice back, show why.
        foreach ($this->repo->logs((int) $id, 50) as $l) {
          if (in_array($l['action'], ['awarded', 'bids_invited'], TRUE)) {
            break;
          }
          if ($l['action'] === 'sent_back') {
            $form['sent_back'] = [
              '#type' => 'inline_template',
              '#template' => '<div class="notice notice--bad"><strong>Rejected by the Director.</strong> {{ text }}</div>',
              '#context' => ['text' => preg_replace('/^Rejected by the Director and sent back to Procurement: /', '', $l['message'])],
              '#weight' => -10,
            ];
            break;
          }
        }
        $form['intro'] = $this->intro('Choose the winning bid', $text);
        $options = [];
        foreach ($ranked as $i => $b) {
          $options[$b['bid_id']] = $b['name'] . ' · ' . $b['rate'] . ' · ' . $b['lead'] . ' · total ' . $b['total'] . ($i === 0 ? ' (best choice)' : '');
        }
        $form['bid'] = [
          '#type' => 'radios',
          '#title' => 'Bids',
          '#options' => $options,
          '#default_value' => $ranked[0]['bid_id'],
          '#required' => TRUE,
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['award'] = [
          '#type' => 'submit',
          '#value' => 'Send to Director',
          '#name' => 'award',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
        ];
        break;

      case 'director_review':
        $vendor = $this->repo->vendor((int) $r['vendor_id']);
        $form['intro'] = $this->intro('Approve this order?', sprintf(
          'Procurement chose %s: %s per %s, total %s. Approve to place the order, or reject it and send it back to Procurement with a reason.',
          $vendor['name'] ?? 'a supplier', Format::inr($r['po_rate']), Format::per($unit), Format::inr($r['po_amount'])
        ));
        $form['comment'] = [
          '#type' => 'textarea',
          '#title' => 'Reason',
          '#rows' => 2,
          '#placeholder' => 'Reason (only needed if you reject)',
          '#title_display' => 'invisible',
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['approve'] = [
          '#type' => 'submit',
          '#value' => 'Approve order',
          '#name' => 'approve',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
        ];
        $form['actions']['send_back'] = [
          '#type' => 'submit',
          '#value' => 'Reject and send back',
          '#name' => 'send_back',
          '#attributes' => ['class' => ['btn', 'btn-ghost', 'btn-danger']],
        ];
        break;

      case 'ordered':
        $form['intro'] = $this->intro('Confirm delivery', 'Did everything arrive at site? Change the number only if less came.');
        $form['received_qty'] = [
          '#type' => 'number',
          '#title' => 'Quantity that arrived (' . $unit . ')',
          '#default_value' => (float) $r['qty'],
          '#min' => 0,
          '#step' => 'any',
          '#required' => TRUE,
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['grn'] = [
          '#type' => 'submit',
          '#value' => 'Confirm delivery',
          '#name' => 'grn',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
        ];
        break;

      case 'received':
        $form['intro'] = $this->intro('Check the supplier\'s bill', 'The bill is already filled in. Click the button to compare it with the order and the delivery.');
        $form['bill'] = [
          '#type' => 'details',
          '#title' => 'Bill details (filled in)',
          '#open' => FALSE,
        ];
        $form['bill']['invoice_no'] = [
          '#parents' => ['invoice_no'],
          '#type' => 'textfield',
          '#title' => 'Bill number',
          '#required' => TRUE,
          '#default_value' => 'INV-' . strtoupper(substr(md5((string) $id), 0, 6)),
        ];
        $form['bill']['invoice_qty'] = [
          '#parents' => ['invoice_qty'],
          '#type' => 'number',
          '#title' => 'Quantity on the bill (' . $unit . ')',
          '#default_value' => (float) $r['qty'],
          '#min' => 0,
          '#step' => 'any',
          '#required' => TRUE,
        ];
        $form['bill']['invoice_amount'] = [
          '#parents' => ['invoice_amount'],
          '#type' => 'number',
          '#title' => 'Bill amount (₹)',
          '#default_value' => (int) $r['po_amount'],
          '#min' => 0,
          '#step' => 'any',
          '#required' => TRUE,
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['match'] = [
          '#type' => 'submit',
          '#value' => 'Check bill',
          '#name' => 'match',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
        ];
        break;

      case 'invoiced':
        $m = $this->workflow->match($r);
        $form['actions'] = ['#type' => 'actions'];
        if ($m['status'] === 'matched') {
          $form['intro'] = $this->intro('Pay the supplier', 'The bill matches the order and the delivery.');
          $form['actions']['pay'] = [
            '#type' => 'submit',
            '#value' => 'Pay ' . $m['invoice_label'],
            '#name' => 'pay',
            '#attributes' => ['class' => ['btn', 'btn-primary']],
          ];
        }
        else {
          $form['intro'] = $this->intro('The bill does not match', implode(' ', $m['notes']));
          $form['actions']['pay_received'] = [
            '#type' => 'submit',
            '#value' => 'Pay ' . $m['payable_label'] . ' for what arrived',
            '#name' => 'pay_received',
            '#attributes' => ['class' => ['btn', 'btn-primary']],
          ];
          $form['actions']['hold'] = [
            '#type' => 'submit',
            '#value' => 'Hold and ask supplier',
            '#name' => 'hold',
            '#attributes' => ['class' => ['btn', 'btn-ghost']],
          ];
        }
        break;
    }
    return $form;
  }

  protected function intro(string $title, string $text): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="stage-intro"><strong>{{ title }}</strong><p>{{ text }}</p></div>',
      '#context' => ['title' => $title, 'text' => $text],
    ];
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $op = $form_state->getTriggeringElement()['#name'] ?? '';
    if (in_array($op, ['reject', 'send_back'], TRUE) && trim((string) $form_state->getValue('comment')) === '') {
      $form_state->setErrorByName('comment', 'Please give a reason, so the team knows what to change.');
    }
    if ($op === 'invite' && !array_filter((array) $form_state->getValue('vendors'))) {
      $form_state->setErrorByName('vendors', 'Pick at least one supplier to invite.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $id = (int) $form_state->get('request_id');
    $r = $this->repo->request($id);
    $a = $this->currentUser();
    $uid = (int) $a->id();
    if (!$r || !$this->workflow->canAct($r, $a)) {
      $this->messenger()->addError('This request has moved on. Refresh to see its current stage.');
      return;
    }
    $op = $form_state->getTriggeringElement()['#name'] ?? '';
    $comment = trim((string) $form_state->getValue('comment'));
    $unit = $r['unit'];
    $me = $this->repo->person($uid);
    $msg = '';

    switch ($op) {
      case 'approve':
        if ($r['stage'] === 'pm_review') {
          $this->repo->updateRequest($id, ['stage' => 'approved', 'approved_pm' => $uid]);
          $this->repo->log($id, $uid, 'pm_approved', 'Approved by PM' . ($comment ? ': ' . $comment : '.'));
          $msg = 'Approved. Procurement will now ask suppliers for bids.';
        }
        else {
          $this->bidding->approveOrder($id, $uid, $comment);
          $vendor = $this->repo->vendor((int) $r['vendor_id']);
          $msg = 'Order approved and placed with ' . ($vendor['name'] ?? 'the supplier') . '.';
        }
        break;

      case 'send_back':
        $this->bidding->sendBack($id, $uid, $comment);
        $msg = 'Rejected and sent back to Procurement with your reason.';
        break;

      case 'invite':
        $res = $this->bidding->invite($id, array_keys(array_filter((array) $form_state->getValue('vendors'))), $uid);
        $this->messenger()->addStatus('Bids invited from ' . implode(', ', $res['invited']) . '. They will bid in their supplier portal; bidding closes when everyone has bid.');
        return;

      case 'close':
        $this->bidding->close($id, $uid, TRUE);
        $this->messenger()->addStatus('Bidding closed. Now choose the winning bid.');
        return;

      case 'award':
        $stage = $this->bidding->award($id, (int) $form_state->getValue('bid'), $uid);
        $r2 = $this->repo->request($id);
        $vendor = $this->repo->vendor((int) $r2['vendor_id']);
        $msg = 'Winner chosen: ' . ($vendor['name'] ?? '') . ' for ' . Format::inr($r2['po_amount']) . '. Sent to the Director for approval.';
        break;

      case 'reject':
        $this->repo->updateRequest($id, ['stage' => 'rejected', 'rejected_stage' => $r['stage']]);
        $this->repo->log($id, $uid, 'rejected', 'Rejected by ' . $me['title'] . ': ' . $comment);
        $requester = $this->repo->person((int) $r['created_by']);
        $this->repo->log($id, 0, 'notified', 'WhatsApp alert sent to ' . $requester['name'] . ' with the reason (simulated).');
        $this->messenger()->addStatus('Not approved. ' . $requester['name'] . ' has been told why on WhatsApp (demo).');
        return;

      case 'grn':
        $qty = (float) $form_state->getValue('received_qty');
        $this->repo->updateRequest($id, ['stage' => 'received', 'received_qty' => $qty, 'grn_date' => time()]);
        $this->repo->adjustStock((int) $r['project_id'], (int) $r['item_id'], $qty);
        $short = $qty < (float) $r['qty'] ? ' Short by ' . Format::qty((float) $r['qty'] - $qty) . ' ' . $unit . '.' : '';
        $this->repo->log($id, $uid, 'received', 'Delivery confirmed: ' . Format::qty($qty) . ' ' . $unit . ' arrived at site.' . $short . ($comment ? ' ' . $comment : ''));
        $msg = 'Delivery confirmed.' . $short;
        break;

      case 'match':
        $values = [
          'invoice_no' => trim((string) $form_state->getValue('invoice_no')),
          'invoice_qty' => (float) $form_state->getValue('invoice_qty'),
          'invoice_amount' => (int) round((float) $form_state->getValue('invoice_amount')),
        ];
        $m = $this->workflow->match($values + $r);
        $values['match_status'] = $m['status'];
        $values['stage'] = 'invoiced';
        $this->repo->updateRequest($id, $values);
        $this->repo->log($id, $uid, 'invoice_checked', 'Bill ' . $values['invoice_no'] . ' checked against the order and the delivery: ' . ($m['status'] === 'matched' ? 'everything matches.' : 'it does not match. ' . implode(' ', $m['notes'])));
        $msg = $m['status'] === 'matched' ? 'The bill matches. Ready to pay.' : 'The bill does not match: ' . implode(' ', $m['notes']);
        if ($m['status'] !== 'matched') {
          $this->messenger()->addWarning($msg);
          $this->notifyNext($id);
          return;
        }
        break;

      case 'pay':
      case 'pay_received':
        $m = $this->workflow->match($r);
        $amount = $op === 'pay' ? (int) $r['invoice_amount'] : (int) $m['payable'];
        $vendor = $this->repo->vendor((int) $r['vendor_id']);
        $this->repo->updateRequest($id, ['stage' => 'paid', 'paid_amount' => $amount, 'paid_date' => time()]);
        $this->repo->log($id, $uid, 'paid', 'Paid ' . Format::inr($amount) . ' to ' . ($vendor['name'] ?? 'the supplier') . '.' . ($op === 'pay_received' ? ' Paid only for what arrived; ' . $m['difference'] . ' kept back.' : ''));
        $this->messenger()->addStatus('Paid ' . Format::inr($amount) . ' to ' . ($vendor['name'] ?? 'the supplier') . '. This request is done.');
        return;

      case 'hold':
        $vendor = $this->repo->vendor((int) $r['vendor_id']);
        $this->repo->log($id, $uid, 'held', 'Payment on hold. Asked ' . ($vendor['name'] ?? 'the supplier') . ' for a corrected bill.');
        $this->repo->log($id, 0, 'notified', 'Email sent to ' . ($vendor['name'] ?? 'the supplier') . ' asking for a corrected bill (demo).');
        $this->messenger()->addStatus('Payment on hold. The supplier has been asked for a corrected bill (demo).');
        return;
    }

    $this->notifyNext($id, $msg);
  }

  /**
   * Simulated WhatsApp alert to whoever acts next.
   */
  protected function notifyNext(int $id, string $msg = ''): void {
    $r = $this->repo->request($id);
    $waiting = $this->workflow->waitingOn($r);
    if ($waiting && $waiting['names']) {
      $this->repo->log($id, 0, 'notified', 'WhatsApp alert sent to ' . $waiting['names'] . ' (' . $waiting['role'] . ') (demo).');
      $msg .= ' ' . $waiting['names'] . ' has been told on WhatsApp (demo).';
    }
    if (trim($msg) !== '') {
      $this->messenger()->addStatus(trim($msg));
    }
  }

}
