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
 * Raise a material request, with the conversational AI-assist box on top.
 */
class RequestForm extends FormBase {

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
    return 'sthira_request_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $a = $this->currentUser();
    $query = $this->getRequest()->query;
    $pids = $this->repo->accessibleProjectIds($a);
    $projects = [];
    foreach ($this->repo->projects($pids) as $p) {
      $projects[$p['id']] = $p['name'] . ' (' . $p['code'] . ')';
    }
    $items = [];
    foreach ($this->repo->items() as $i) {
      $items[$i['category']][$i['id']] = $i['name'] . ' — per ' . Format::per($i['unit']);
    }

    $form['#attributes']['class'][] = 'request-form';
    $form['#attached']['library'][] = 'sthira/intake';
    $form['#attached']['drupalSettings']['sthiraIntake'] = $this->assistant->intakeSettings($a);
    $form['#cache']['max-age'] = 0;

    $form['head'] = [
      '#type' => 'inline_template',
      '#template' => '<a class="back" href="{{ back }}">← Requests</a><header class="page-head"><div><h1>New request</h1></div></header>',
      '#context' => ['back' => \Drupal\Core\Url::fromRoute('sthira.requests')->toString()],
    ];

    $form['intake'] = [
      '#theme' => 'sthira_intake',
      '#samples' => [
        'Need 400 bags of cement at Skyline by Friday',
        'Urgent: 8 tonnes TMT steel for Greenfield',
        '20 coils of copper wire for the hospital next week',
      ],
    ];

    $form['raw_text'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => ['data-intake' => 'raw'],
    ];

    $form['fields'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['section', 'form-card']],
    ];
    $form['fields']['title'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="section-head"><h2>Details</h2><span class="muted small" data-intake-status></span></div>',
    ];
    $form['fields']['grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-grid']],
    ];
    $g = &$form['fields']['grid'];
    $g['project'] = [
      '#type' => 'select',
      '#title' => 'Project / site',
      '#options' => $projects,
      '#empty_option' => '- Choose project -',
      '#required' => TRUE,
      '#default_value' => $query->get('project'),
      '#attributes' => ['data-intake' => 'project'],
    ];
    $g['item'] = [
      '#type' => 'select',
      '#title' => 'Material',
      '#options' => $items,
      '#empty_option' => '- Choose material -',
      '#required' => TRUE,
      '#default_value' => $query->get('item'),
      '#attributes' => ['data-intake' => 'item'],
    ];
    $g['qty'] = [
      '#type' => 'number',
      '#title' => 'Quantity',
      '#min' => 0.1,
      '#step' => 'any',
      '#required' => TRUE,
      '#default_value' => $query->get('qty'),
      '#attributes' => ['data-intake' => 'qty'],
      '#field_suffix' => '<span data-intake-unit></span>',
    ];
    $g['needed_by'] = [
      '#type' => 'date',
      '#title' => 'Needed by',
      '#required' => TRUE,
      '#default_value' => date('Y-m-d', strtotime('+7 days')),
      '#attributes' => ['data-intake' => 'date', 'min' => date('Y-m-d')],
    ];
    $form['fields']['more'] = [
      '#type' => 'details',
      '#title' => 'Priority and note (optional)',
      '#open' => FALSE,
      '#attributes' => ['class' => ['more']],
    ];
    $form['fields']['more']['priority'] = [
      '#type' => 'select',
      '#title' => 'Priority',
      '#options' => ['normal' => 'Normal', 'urgent' => 'Urgent'],
      '#default_value' => 'normal',
      '#attributes' => ['data-intake' => 'priority'],
    ];
    $form['fields']['more']['note'] = [
      '#type' => 'textarea',
      '#title' => 'Note',
      '#rows' => 2,
      '#placeholder' => 'Which work is this for?',
      '#attributes' => ['data-intake' => 'note'],
    ];
    $form['fields']['route'] = [
      '#type' => 'inline_template',
      '#template' => '<div class="route-preview" data-intake-route hidden></div>',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Send request',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $pids = $this->repo->accessibleProjectIds($this->currentUser());
    if (!in_array((int) $form_state->getValue('project'), $pids, TRUE)) {
      $form_state->setErrorByName('project', 'Choose one of your projects.');
    }
    if ((float) $form_state->getValue('qty') <= 0) {
      $form_state->setErrorByName('qty', 'Quantity must be more than zero.');
    }
    if (!$this->repo->item((int) $form_state->getValue('item'))) {
      $form_state->setErrorByName('item', 'Choose a material.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $a = $this->currentUser();
    $item = $this->repo->item((int) $form_state->getValue('item'));
    $project = $this->repo->project((int) $form_state->getValue('project'));
    $qty = (float) $form_state->getValue('qty');
    $amount = (int) round($qty * (float) $item['std_rate']);
    $raw = trim((string) $form_state->getValue('raw_text'));

    $start = $this->workflow->startStage(['project_id' => (int) $project['id'], 'est_amount' => $amount], $a);
    $id = $this->repo->createRequest([
      'project_id' => (int) $project['id'],
      'item_id' => (int) $item['id'],
      'qty' => $qty,
      'unit' => $item['unit'],
      'est_rate' => (int) $item['std_rate'],
      'est_amount' => $amount,
      'needed_by' => $form_state->getValue('needed_by'),
      'priority' => $form_state->getValue('priority') ?: 'normal',
      'note' => trim((string) $form_state->getValue('note')),
      'raw_text' => $raw,
      'stage' => $start['stage'],
      'approved_pm' => $start['approved_pm'],
      'approved_director' => $start['approved_director'],
      'created_by' => (int) $a->id(),
    ]);
    $r = $this->repo->request($id);
    $this->repo->log($id, (int) $a->id(), 'created', 'Raised ' . $r['ref'] . ': ' . Format::qty($qty) . ' ' . $item['unit'] . ' of ' . $item['name'] . ' for ' . $this->assistant->shortProject($project['name']) . ' (est. ' . Format::inr($amount) . ')' . ($raw ? ' using AI assist' : '') . '.');

    if ($start['stage'] === 'approved') {
      $this->repo->log($id, (int) $a->id(), 'pm_approved', 'Approved on raising: raised by the project manager.');
    }
    $waiting = $this->workflow->waitingOn($r);
    $names = $waiting['names'] ?: ($waiting['role'] ?? 'the team');
    $why = [
      'approved' => 'to invite bids from suppliers',
      'pm_review' => 'for approval',
    ][$start['stage']] ?? '';
    $this->repo->log($id, 0, 'notified', 'WhatsApp alert sent to ' . $names . ' ' . $why . ' (demo).');
    $this->messenger()->addStatus($r['ref'] . ' sent. ' . $names . ' has been told on WhatsApp ' . $why . ' (demo).');
    $form_state->setRedirect('sthira.request', ['id' => $id]);
  }

}
