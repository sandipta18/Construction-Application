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
    $form['rule'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="muted">Every winning bid is approved by the project manager who raised the request before the order is placed.</p>',
    ];
    $form['demo_mode'] = [
      '#type' => 'checkbox',
      '#title' => 'Demo mode (role switcher and simulated WhatsApp alerts)',
      '#default_value' => $config->get('demo_mode'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    if (!$this->currentUser()->hasPermission('manage sthira settings')) {
      return;
    }
    $this->configFactory()->getEditable('sthira.settings')->set('demo_mode', (bool) $form_state->getValue('demo_mode'))->save();
    $this->messenger()->addStatus('Settings saved.');
  }

}
