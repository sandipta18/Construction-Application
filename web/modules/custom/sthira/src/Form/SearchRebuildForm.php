<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Rebuild the search index / re-check the local model.
 */
class SearchRebuildForm extends FormBase {

  public function getFormId() {
    return 'sthira_search_rebuild';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['rebuild'] = ['#type' => 'submit', '#value' => $this->t('Rebuild index'), '#name' => 'rebuild', '#attributes' => ['class' => ['btn-primary']]];
    $form['actions']['check'] = ['#type' => 'submit', '#value' => $this->t('Check model again'), '#name' => 'check'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $embedder = \Drupal::service('sthira.embedder');
    $status = $embedder->status(TRUE);
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'rebuild') {
      $s = \Drupal::service('sthira.search_index')->rebuild();
      $this->messenger()->addStatus($this->t('Index rebuilt: @docs documents (@added new, @updated changed, @removed removed), @embedded embedded.', [
        '@docs' => $s['docs'], '@added' => $s['added'], '@updated' => $s['updated'], '@removed' => $s['removed'], '@embedded' => $s['embedded'],
      ]));
    }
    else {
      $added = $status['ok'] ? \Drupal::service('sthira.search_index')->embedMissing() : 0;
      $this->messenger()->{$status['ok'] ? 'addStatus' : 'addWarning'}($status['message'] . ($added ? ' ' . $added . ' documents embedded.' : ''));
    }
  }

}
