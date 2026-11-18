<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sthira\Repository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Procurement's approve / reject panel for a pending vendor.
 */
class VendorReviewForm extends FormBase {

  protected int $vendorId = 0;

  public function __construct(protected Repository $repo) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('sthira.repository'));
  }

  public function getFormId() {
    // One form per vendor on the same page.
    return 'sthira_vendor_review_form_' . $this->vendorId;
  }

  public function setVendorId(int $id): static {
    $this->vendorId = $id;
    return $this;
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $vendor_id = NULL) {
    $form_state->set('vendor_id', (int) $vendor_id);
    $form['#attributes']['class'][] = 'vendor-review';
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => 'Note',
      '#title_display' => 'invisible',
      '#placeholder' => 'Note to the vendor (needed to reject)',
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
      '#attributes' => ['class' => ['btn', 'btn-danger']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'reject' && trim((string) $form_state->getValue('note')) === '') {
      $form_state->setErrorByName('note', 'Tell the vendor why, so they can fix it and resubmit.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->currentUser()->hasPermission('approve vendors')) {
      return;
    }
    $node = $this->repo->vendorNode((int) $form_state->get('vendor_id'));
    if (!$node) {
      return;
    }
    $approve = ($form_state->getTriggeringElement()['#name'] ?? '') === 'approve';
    $note = trim((string) $form_state->getValue('note'));
    $node->set('field_vendor_status', $approve ? 'approved' : 'rejected');
    $node->set('field_review_note', $note ?: NULL);
    $node->save();

    $uid = (int) $this->currentUser()->id();
    $name = $node->label();
    $this->repo->log(0, $uid, $approve ? 'vendor_approved' : 'vendor_rejected', ($approve ? 'Approved vendor ' : 'Rejected vendor ') . $name . ($note ? ': ' . $note : '.'));
    $this->repo->log(0, 0, 'notified', 'Email sent to ' . $name . ' about the verification result (simulated).');
    $this->messenger()->addStatus($approve
      ? $name . ' is approved. They can now add rates and receive purchase orders.'
      : $name . ' was rejected. They have been told why and can resubmit.');
  }

}
