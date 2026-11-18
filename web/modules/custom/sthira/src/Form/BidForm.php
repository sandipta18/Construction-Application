<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sthira\Bidding;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A supplier's bid on one request, in the supplier portal.
 */
class BidForm extends FormBase {

  protected int $requestId = 0;

  public function __construct(protected Repository $repo, protected Bidding $bidding) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('sthira.repository'), $container->get('sthira.bidding'));
  }

  public function setRequestId(int $id): static {
    $this->requestId = $id;
    return $this;
  }

  public function getFormId() {
    return 'sthira_bid_form_' . $this->requestId;
  }

  /**
   * The supplier company of the current user, if it may bid on this request.
   */
  protected function bidder(array $r): ?array {
    $v = $this->repo->vendorForUser((int) $this->currentUser()->id());
    if (!$v || $v['status'] !== 'approved' || $r['stage'] !== 'bidding' || !empty($r['bids_closed']) || !in_array($v['id'], $r['invited'], TRUE)) {
      return NULL;
    }
    return $v;
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $r = $this->repo->request($this->requestId);
    $form['#cache']['max-age'] = 0;
    if (!$r || !($v = $this->bidder($r))) {
      return $form;
    }
    $form['#attributes']['class'][] = 'bid-form';
    $mine = $this->repo->bidFor($r['id'], $v['id']);
    $lead = 3;
    foreach ($this->repo->vendorRates($v['id']) as $rate) {
      if ((int) $rate['item_id'] === (int) $r['item_id']) {
        $lead = (int) $rate['lead_days'];
      }
    }
    $form['row'] = ['#type' => 'container', '#attributes' => ['class' => ['bid-row']]];
    $form['row']['rate'] = [
      '#type' => 'number',
      '#title' => 'Your price (₹ per ' . Format::per($r['unit']) . ')',
      '#min' => 1,
      '#step' => 'any',
      '#required' => TRUE,
      '#default_value' => $mine ? $mine['rate'] : NULL,
      '#parents' => ['rate'],
    ];
    $form['row']['lead_days'] = [
      '#type' => 'number',
      '#title' => 'Delivery in (days)',
      '#min' => 1,
      '#max' => 90,
      '#required' => TRUE,
      '#default_value' => $mine ? $mine['lead_days'] : $lead,
      '#parents' => ['lead_days'],
    ];
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => 'Note (optional)',
      '#maxlength' => 200,
      '#default_value' => $mine['note'] ?? '',
      '#placeholder' => 'e.g. Price includes delivery to site',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $mine ? 'Update bid' : 'Submit bid',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $r = $this->repo->request($this->requestId);
    $v = $r ? $this->bidder($r) : NULL;
    if (!$v) {
      $this->messenger()->addError('Bidding on this request has closed.');
      return;
    }
    $rate = (float) $form_state->getValue('rate');
    $lead = (int) $form_state->getValue('lead_days');
    $this->bidding->submit($r['id'], $v, $rate, $lead, trim((string) $form_state->getValue('note')), (int) $this->currentUser()->id());
    $this->messenger()->addStatus('Bid sent: ' . Format::inr($rate) . ' per ' . Format::per($r['unit']) . ', total ' . Format::inr($rate * (float) $r['qty']) . '. Procurement will compare it with the other bids.');
  }

}
