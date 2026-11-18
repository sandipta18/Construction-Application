<?php

namespace Drupal\sthira\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\sthira\Repository;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public "Register as a vendor" form.
 *
 * Creates the vendor's login and a Vendor content item with status
 * "Pending verification". Procurement approves or rejects it.
 */
class VendorRegisterForm extends FormBase {

  public function __construct(protected Repository $repo) {}

  public static function create(ContainerInterface $container) {
    return new static($container->get('sthira.repository'));
  }

  public function getFormId() {
    return 'sthira_vendor_register_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attributes']['class'][] = 'vendor-register';
    $form['#cache']['max-age'] = 0;

    $form['company'] = [
      '#type' => 'fieldset',
      '#title' => 'Company',
    ];
    $form['company']['name'] = ['#type' => 'textfield', '#title' => 'Company name', '#required' => TRUE];
    $form['company']['city'] = ['#type' => 'textfield', '#title' => 'City', '#required' => TRUE];
    $form['company']['supplies'] = [
      '#type' => 'checkboxes',
      '#title' => 'What do you supply?',
      '#options' => array_combine(array_keys(Repository::CATEGORIES), Repository::CATEGORIES),
      '#required' => TRUE,
    ];
    $form['company']['gstin'] = ['#type' => 'textfield', '#title' => 'GSTIN', '#required' => TRUE, '#maxlength' => 15];
    $form['company']['pan'] = ['#type' => 'textfield', '#title' => 'PAN', '#required' => TRUE, '#maxlength' => 10];
    $form['company']['bank'] = [
      '#type' => 'textarea',
      '#title' => 'Bank details',
      '#rows' => 2,
      '#placeholder' => 'Bank name, account number, IFSC',
    ];
    $form['company']['documents'] = [
      '#type' => 'managed_file',
      '#title' => 'Documents',
      '#description' => 'GST certificate, PAN card, cancelled cheque, MSME certificate. PDF or image, up to 5 MB each.',
      '#multiple' => TRUE,
      '#upload_location' => 'public://vendor-docs',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf jpg jpeg png'],
        'FileSizeLimit' => ['fileLimit' => 5 * 1024 * 1024],
      ],
    ];

    $form['contact'] = [
      '#type' => 'fieldset',
      '#title' => 'Contact and login',
    ];
    $form['contact']['person'] = ['#type' => 'textfield', '#title' => 'Contact person', '#required' => TRUE];
    $form['contact']['phone'] = ['#type' => 'tel', '#title' => 'Phone', '#required' => TRUE];
    $form['contact']['mail'] = ['#type' => 'email', '#title' => 'Email', '#required' => TRUE];
    $form['contact']['username'] = ['#type' => 'textfield', '#title' => 'Username', '#required' => TRUE, '#maxlength' => 60];
    $form['contact']['pass'] = ['#type' => 'password', '#title' => 'Password', '#required' => TRUE, '#size' => 25];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Submit for verification',
      '#attributes' => ['class' => ['btn', 'btn-primary']],
    ];
    $form['signin'] = [
      '#type' => 'inline_template',
      '#template' => '<p class="muted small register-foot">Already registered? <a href="{{ url }}">Sign in</a></p>',
      '#weight' => 1000,
      '#context' => ['url' => Url::fromRoute('user.login')->toString()],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    if ($storage->loadByProperties(['name' => trim($form_state->getValue('username'))])) {
      $form_state->setErrorByName('username', 'That username is taken. Please choose another.');
    }
    if ($storage->loadByProperties(['mail' => trim($form_state->getValue('mail'))])) {
      $form_state->setErrorByName('mail', 'An account with this email already exists. Please sign in instead.');
    }
    if (!preg_match('/^[0-9]{2}[A-Z0-9]{13}$/i', trim($form_state->getValue('gstin')))) {
      $form_state->setErrorByName('gstin', 'GSTIN should be 15 characters, starting with the 2-digit state code.');
    }
    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/i', trim($form_state->getValue('pan')))) {
      $form_state->setErrorByName('pan', 'PAN should look like ABCDE1234F.');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $v = fn($k) => trim((string) $form_state->getValue($k));
    $person = $v('person');

    $account = User::create([
      'name' => $v('username'),
      'mail' => $v('mail'),
      'pass' => $form_state->getValue('pass'),
      'status' => 1,
      'roles' => ['vendor'],
    ]);
    $account->save();

    // Show the vendor's name across the app.
    $state = \Drupal::state();
    $people = $state->get('sthira.people', []);
    $people[(int) $account->id()] = ['name' => $person, 'title' => 'Vendor', 'username' => $account->getAccountName()];
    $state->set('sthira.people', $people);

    $files = [];
    foreach ((array) $form_state->getValue('documents') as $fid) {
      if ($fid && ($file = File::load($fid))) {
        $file->setPermanent();
        $file->setOwnerId((int) $account->id());
        $file->save();
        $files[] = ['target_id' => (int) $fid];
      }
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type' => 'vendor',
      'title' => $v('name'),
      'uid' => (int) $account->id(),
      'status' => 1,
      'field_location' => $v('city'),
      'field_supplies' => array_values(array_filter($form_state->getValue('supplies'))),
      'field_contact' => $person,
      'field_phone' => $v('phone'),
      'field_gstin' => strtoupper($v('gstin')),
      'field_pan' => strtoupper($v('pan')),
      'field_bank' => $v('bank'),
      'field_documents' => $files,
      'field_vendor_status' => 'pending',
      'field_vendor_user' => (int) $account->id(),
    ]);
    $node->save();

    $this->repo->log(0, (int) $account->id(), 'vendor_registered', $v('name') . ' registered as a vendor (' . count($files) . ' documents). Waiting for procurement to verify.');
    $officers = array_map(fn($uid) => $this->repo->person($uid)['name'], $this->repo->usersWithRole('procurement_officer'));
    $this->repo->log(0, 0, 'notified', 'Email sent to ' . ($officers ? implode(', ', $officers) : 'procurement') . ' to verify ' . $v('name') . ' (simulated).');

    user_login_finalize($account);
    $this->messenger()->addStatus('Thank you. Your registration has been sent to our procurement team for verification.');
    $form_state->setRedirect('sthira.vendor_portal');
  }

}
