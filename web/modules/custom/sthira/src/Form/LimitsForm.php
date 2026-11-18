<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sthira\Format;

/**
 * Edit approval limits (stored as config, exportable with config sync).
 */
class LimitsForm extends FormBase {

  public function getFormId() {
    return 'sthira_limits_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sthira.settings');
    $form['#attributes']['class'][] = 'limits-form';
    $form['pm_limit'] = [
      '#type' => 'number',
      '#title' => 'Project Manager can approve up to (₹)',
      '#default_value' => $config->get('pm_limit'),
      '#min' => 0,
      '#step' => 1000,
      '#required' => TRUE,
      '#description' => 'Anything above this is routed to the Director automatically.',
    ];
    $form['demo_mode'] = [
      '#type' => 'checkbox',
      '#title' => 'Demo mode (role switcher and simulated WhatsApp alerts)',
      '#default_value' => $config->get('demo_mode'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save limits',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->currentUser()->hasPermission('manage sthira settings')) {
      return;
    }
    $config = $this->configFactory()->getEditable('sthira.settings');
    $old = (int) $config->get('pm_limit');
    $new = (int) $form_state->getValue('pm_limit');
    $config->set('pm_limit', $new)->set('demo_mode', (bool) $form_state->getValue('demo_mode'))->save();
    if ($old !== $new) {
      \Drupal::service('sthira.repository')->log(0, (int) $this->currentUser()->id(), 'settings', 'PM approval limit changed from ' . Format::inr($old) . ' to ' . Format::inr($new) . '.');
    }
    $this->messenger()->addStatus('Approval limits saved. New requests follow the updated rule.');
  }

}
