<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sthira\Format;
use Drupal\sthira\Repository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Create or edit a project with as few questions as possible.
 *
 * Only the name and city are required. The code is generated, progress starts
 * at 0 and the material budget is one number, split across material types
 * behind the scenes so budget tracking keeps working.
 */
class ProjectForm extends FormBase {

  /**
   * Typical share of a building's material budget, by material type (%).
   */
  const BUDGET_MIX = [
    'steel' => 35,
    'cement' => 15,
    'finishing' => 15,
    'bricks' => 10,
    'electrical' => 10,
    'aggregates' => 8,
    'plumbing' => 7,
  ];

  public function __construct(protected Repository $repo) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('sthira.repository'));
  }

  public function getFormId() {
    return 'sthira_project_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL) {
    $p = $id ? $this->repo->project((int) $id) : NULL;
    if ($id && !$p) {
      throw new NotFoundHttpException();
    }
    $form_state->set('project_id', $p ? (int) $p['id'] : NULL);
    $isNew = !$p;
    // Read dates and photo from the node itself: the app fills blanks with today.
    $node = $p ? \Drupal::entityTypeManager()->getStorage('node')->load($p['id']) : NULL;
    $form['#attributes']['class'][] = 'project-form';
    $form['#cache']['max-age'] = 0;

    $back = $p ? Url::fromRoute('sthira.project', ['id' => $p['id']])->toString() : Url::fromRoute('sthira.projects')->toString();
    $form['head'] = [
      '#type' => 'inline_template',
      '#template' => '<a class="back" href="{{ back }}">← {{ back_label }}</a><header class="page-head"><div><h1>{{ title }}</h1><p class="sub">{{ sub }}</p></div></header>',
      '#context' => [
        'back' => $back,
        'back_label' => $p ? $p['name'] : 'Projects',
        'title' => $isNew ? 'New project' : 'Edit project',
        'sub' => $isNew
          ? 'Only the name and city are needed. The project code (' . $this->nextCode() . ') is set for you.'
          : 'Project code ' . $p['code'] . '.',
      ],
    ];

    $form['main'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['section', 'form-card']],
    ];
    $form['main']['name'] = [
      '#type' => 'textfield',
      '#title' => 'Project name',
      '#required' => TRUE,
      '#maxlength' => 120,
      '#default_value' => $p['name'] ?? '',
      '#placeholder' => 'e.g. Lakeview Apartments – Tower A',
      '#attributes' => ['autofocus' => 'autofocus'],
    ];
    $form['main']['row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-grid']],
    ];
    $form['main']['row']['location'] = [
      '#type' => 'textfield',
      '#title' => 'City',
      '#required' => TRUE,
      '#maxlength' => 60,
      '#default_value' => $p['location'] ?? '',
      '#placeholder' => 'e.g. Pune',
    ];
    $pms = $this->projectManagers();
    $currentPm = $p ? (array_values(array_intersect($p['team'], array_keys($pms)))[0] ?? '') : '';
    $form['main']['row']['pm'] = [
      '#type' => 'select',
      '#title' => 'Project manager',
      '#options' => $pms,
      '#empty_option' => 'Choose later',
      '#default_value' => $currentPm,
    ];

    $budgetTotal = $p ? array_sum($p['budgets']) : 0;
    $form['main']['more'] = [
      '#type' => 'details',
      '#title' => 'More details (optional)',
      '#open' => !$isNew,
      '#attributes' => ['class' => ['more']],
    ];
    $more = &$form['main']['more'];
    $more['grid1'] = ['#type' => 'container', '#attributes' => ['class' => ['form-grid']]];
    $more['grid1']['client'] = [
      '#type' => 'textfield',
      '#title' => 'Client',
      '#maxlength' => 120,
      '#default_value' => $p['client'] ?? '',
      '#placeholder' => 'Who the building is for',
    ];
    $more['grid1']['budget'] = [
      '#type' => 'number',
      '#title' => 'Material budget (₹)',
      '#min' => 0,
      '#step' => 1000,
      '#default_value' => $budgetTotal ? (int) $budgetTotal : '',
      '#placeholder' => 'e.g. 5000000',
      '#description' => $budgetTotal ? 'Now ' . Format::short($budgetTotal) . '. One total for all materials.' : 'One total for all materials. Used to track spending.',
    ];
    $more['grid2'] = ['#type' => 'container', '#attributes' => ['class' => ['form-grid']]];
    $more['grid2']['start_date'] = [
      '#type' => 'date',
      '#title' => 'Start date',
      '#default_value' => $node ? ($node->get('field_start_date')->value ?? '') : date('Y-m-d'),
    ];
    $more['grid2']['end_date'] = [
      '#type' => 'date',
      '#title' => 'Expected finish',
      '#default_value' => $node ? ($node->get('field_end_date')->value ?? '') : '',
    ];
    if (!$isNew) {
      $more['grid3'] = ['#type' => 'container', '#attributes' => ['class' => ['form-grid']]];
      $more['grid3']['phase'] = [
        '#type' => 'textfield',
        '#title' => 'Current stage of work',
        '#maxlength' => 80,
        '#default_value' => $p['phase'],
        '#placeholder' => 'e.g. Foundation',
      ];
      $more['grid3']['progress'] = [
        '#type' => 'number',
        '#title' => 'Work done (%)',
        '#min' => 0,
        '#max' => 100,
        '#default_value' => $p['progress'],
      ];
    }
    $more['description'] = [
      '#type' => 'textarea',
      '#title' => 'About the project',
      '#rows' => 3,
      '#default_value' => $p['description'] ?? '',
      '#placeholder' => 'A line or two for suppliers: what is being built and where.',
      '#description' => 'Shown to suppliers on the homepage. No prices.',
    ];
    $more['photo'] = [
      '#type' => 'managed_file',
      '#title' => 'Site photo',
      '#upload_location' => 'public://infra-radar/projects',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'jpg jpeg png webp'],
        'FileSizeLimit' => ['fileLimit' => 4 * 1024 * 1024],
      ],
      '#description' => 'A wide photo works best. JPG, PNG or WebP, up to 4 MB.',
      '#default_value' => ($node && !$node->get('field_photo')->isEmpty()) ? [(int) $node->get('field_photo')->target_id] : NULL,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $isNew ? 'Create project' : 'Save changes',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => 'Cancel',
      '#url' => Url::fromUserInput($back),
      '#attributes' => ['class' => ['btn', 'btn-ghost']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $start = $form_state->getValue('start_date');
    $end = $form_state->getValue('end_date');
    if ($start && $end && $end < $start) {
      $form_state->setErrorByName('end_date', 'The finish date is before the start date.');
    }
    $pm = $form_state->getValue('pm');
    if ($pm !== '' && $pm !== NULL && !isset($this->projectManagers()[(int) $pm])) {
      $form_state->setErrorByName('pm', 'Choose a project manager from the list.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $id = $form_state->get('project_id');
    $isNew = !$id;
    $node = $isNew
      ? $storage->create(['type' => 'project', 'uid' => $this->currentUser()->id(), 'status' => 1])
      : $storage->load($id);

    $node->setTitle(trim($form_state->getValue('name')));
    $node->set('field_location', trim($form_state->getValue('location')));
    $node->set('field_client', trim((string) $form_state->getValue('client')));
    $node->set('field_description', trim((string) $form_state->getValue('description')));
    $node->set('field_start_date', $form_state->getValue('start_date') ?: date('Y-m-d'));
    $node->set('field_end_date', $form_state->getValue('end_date') ?: NULL);
    if ($isNew) {
      $node->set('field_code', $this->nextCode());
      $node->set('field_progress', 0);
      $node->set('field_phase', 'Planning');
    }
    else {
      $node->set('field_phase', trim((string) $form_state->getValue('phase')));
      $node->set('field_progress', max(0, min(100, (int) $form_state->getValue('progress'))));
    }

    // Team: the chosen PM replaces any other PM; other members stay.
    $pms = array_keys($this->projectManagers());
    $team = array_values(array_diff(array_map(fn($t) => (int) $t['target_id'], $node->get('field_team')->getValue()), $pms));
    if ($pm = (int) $form_state->getValue('pm')) {
      $team[] = $pm;
    }
    $node->set('field_team', array_map(fn($uid) => ['target_id' => $uid], array_values(array_unique($team))));

    // One budget number, split across material types.
    $budget = $form_state->getValue('budget');
    if ($budget !== '' && $budget !== NULL) {
      $this->setBudget($node, (float) $budget);
    }

    $fid = $form_state->getValue('photo')[0] ?? NULL;
    if ($fid && ($file = \Drupal::entityTypeManager()->getStorage('file')->load($fid))) {
      $file->setPermanent();
      $file->save();
      $node->set('field_photo', ['target_id' => $fid, 'alt' => $node->label()]);
    }
    elseif (!$isNew && !$fid) {
      // The photo was removed in the form.
      $node->set('field_photo', NULL);
    }
    $node->save();
    $this->repo->reset();

    $code = $node->get('field_code')->value;
    $this->repo->log(0, (int) $this->currentUser()->id(), 'project', ($isNew ? 'Created project ' : 'Updated project ') . $code . ': ' . $node->label() . '.');
    $this->messenger()->addStatus($isNew ? $node->label() . ' created (' . $code . ').' : 'Changes saved.');
    $form_state->setRedirect('sthira.project', ['id' => $node->id()]);
  }

  /**
   * Spreads a total budget across material types, keeping the project's
   * current mix when it has one.
   */
  protected function setBudget($node, float $total): void {
    $current = [];
    foreach (array_keys(Repository::CATEGORIES) as $key) {
      $current[$key] = (float) $node->get('field_budget_' . $key)->value;
    }
    $sum = array_sum($current);
    if (abs($sum - $total) < 1) {
      return;
    }
    $mix = $sum > 0 ? array_map(fn($v) => $v / $sum * 100, $current) : self::BUDGET_MIX;
    $left = $total;
    $keys = array_keys(Repository::CATEGORIES);
    foreach ($keys as $i => $key) {
      $share = ($mix[$key] ?? 0) / 100 * $total;
      // Round to the nearest thousand; the last type takes what is left.
      $amount = $i === count($keys) - 1 ? max(0, $left) : round($share / 1000) * 1000;
      $left -= $amount;
      $node->set('field_budget_' . $key, $amount > 0 ? $amount : NULL);
    }
  }

  /**
   * Project managers by full name.
   */
  protected function projectManagers(): array {
    $out = [];
    foreach ($this->repo->usersWithRole('project_manager') as $uid) {
      $out[(int) $uid] = $this->repo->person((int) $uid)['name'];
    }
    asort($out);
    return $out;
  }

  /**
   * Next code after the highest P-number in use, e.g. P-104.
   */
  protected function nextCode(): string {
    $max = 100;
    foreach ($this->repo->projects() as $p) {
      if (preg_match('/(\d+)$/', (string) $p['code'], $m)) {
        $max = max($max, (int) $m[1]);
      }
    }
    return 'P-' . ($max + 1);
  }

}
