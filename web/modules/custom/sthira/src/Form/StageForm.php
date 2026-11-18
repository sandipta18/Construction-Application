<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sthira\Assistant;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;
use Drupal\sthira\Workflow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The action panel on a request: approve, issue PO, receive, match, pay.
 */
class StageForm extends FormBase {

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
      case 'director_review':
        $isPm = $r['stage'] === 'pm_review';
        $next = $isPm && $this->workflow->needsDirector($r)
          ? 'It costs more than ' . Format::inr($this->workflow->pmLimit()) . ', so the Director approves it after you.'
          : 'Once approved, Procurement orders it from a supplier.';
        $form['intro'] = $this->intro('Approve this request?', $next);
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
        $ranked = $this->assistant->rankVendors($r);
        $options = [];
        foreach ($ranked as $i => $v) {
          $options[$v['vendor_id']] = $v['name'] . ' · ' . $v['rate'] . ($i === 0 ? ' (best choice)' : '');
        }
        $form['intro'] = $this->intro('Place the order', $ranked ? 'The best supplier is already picked for you. Just click the button.' : 'No supplier has a price for this material yet.');
        $form['vendor'] = [
          '#type' => 'radios',
          '#title' => 'Supplier',
          '#options' => $options,
          '#default_value' => $ranked ? $ranked[0]['vendor_id'] : NULL,
          '#required' => TRUE,
        ];
        $form['more'] = [
          '#type' => 'details',
          '#title' => 'Change the price (optional)',
          '#open' => FALSE,
        ];
        $form['more']['rate'] = [
          '#type' => 'number',
          '#title' => 'Agreed price (₹ per ' . Format::per($unit) . ')',
          '#min' => 0,
          '#step' => 'any',
          '#parents' => ['rate'],
        ];
        $form['actions'] = ['#type' => 'actions'];
        $form['actions']['po'] = [
          '#type' => 'submit',
          '#value' => 'Place order',
          '#name' => 'po',
          '#attributes' => ['class' => ['btn', 'btn-primary']],
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
    if ($op === 'reject' && trim((string) $form_state->getValue('comment')) === '') {
      $form_state->setErrorByName('comment', 'Please give a reason for rejecting, so the site team knows what to change.');
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
          $toDirector = $this->workflow->needsDirector($r);
          $this->repo->updateRequest($id, ['stage' => $toDirector ? 'director_review' : 'approved', 'approved_pm' => $uid]);
          $this->repo->log($id, $uid, 'pm_approved', 'Approved by PM' . ($comment ? ': ' . $comment : '.') . ($toDirector ? ' Above the ' . Format::inr($this->workflow->pmLimit()) . ' limit, so routed to the Director.' : ''));
          $msg = $toDirector ? 'Approved. It now goes to the Director, as it costs more than your limit.' : 'Approved. Procurement will now order it.';
        }
        else {
          $this->repo->updateRequest($id, ['stage' => 'approved', 'approved_director' => $uid]);
          $this->repo->log($id, $uid, 'director_approved', 'Approved by Director' . ($comment ? ': ' . $comment : '.'));
          $msg = 'Approved. Procurement will now order it.';
        }
        break;

      case 'reject':
        $this->repo->updateRequest($id, ['stage' => 'rejected', 'rejected_stage' => $r['stage']]);
        $this->repo->log($id, $uid, 'rejected', 'Rejected by ' . $me['title'] . ': ' . $comment);
        $requester = $this->repo->person((int) $r['created_by']);
        $this->repo->log($id, 0, 'notified', 'WhatsApp alert sent to ' . $requester['name'] . ' with the reason (simulated).');
        $this->messenger()->addStatus('Not approved. ' . $requester['name'] . ' has been told why on WhatsApp (demo).');
        return;

      case 'po':
        $vendorId = (int) $form_state->getValue('vendor');
        $ranked = $this->assistant->rankVendors($r);
        $chosen = NULL;
        foreach ($ranked as $v) {
          if ($v['vendor_id'] === $vendorId) {
            $chosen = $v;
          }
        }
        if (!$chosen) {
          $this->messenger()->addError('Pick a supplier that has a price for this material.');
          return;
        }
        $rate = (float) $form_state->getValue('rate') ?: $chosen['rate_raw'];
        $po = $this->repo->nextPoNumber();
        $amount = (int) round($rate * (float) $r['qty']);
        $this->repo->updateRequest($id, [
          'stage' => 'ordered',
          'vendor_id' => $vendorId,
          'po_number' => $po,
          'po_rate' => (int) round($rate),
          'po_amount' => $amount,
          'po_date' => time(),
        ]);
        $override = $ranked[0]['vendor_id'] !== $vendorId ? ' Chose a different supplier than suggested (' . $ranked[0]['name'] . ').' : ' Went with the suggested supplier.';
        $this->repo->log($id, $uid, 'po_issued', 'Order ' . $po . ' placed with ' . $chosen['name'] . ' at ' . Format::inr($rate) . ' per ' . Format::per($unit) . ' (' . Format::inr($amount) . ').' . $override);
        $this->repo->log($id, 0, 'notified', 'Order ' . $po . ' sent to ' . $chosen['name'] . ' on email and WhatsApp (demo).');
        $msg = 'Order placed with ' . $chosen['name'] . ' for ' . Format::inr($amount) . '.';
        break;

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
