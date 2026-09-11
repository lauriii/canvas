<?php

declare(strict_types=1);

namespace Drupal\multi_frontend_test\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * A form covering every branch the describer has.
 *
 * Scalars with and without constraints, both choice shapes, a nested group,
 * and one element that cannot be described at all.
 */
final class DescribableTestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'multi_frontend_test_describable';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#description' => $this->t('Where to reach you.'),
      '#placeholder' => 'you@example.com',
    ];
    $form['nickname'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nickname'),
      '#maxlength' => 32,
    ];
    $form['plan'] = [
      '#type' => 'select',
      '#title' => $this->t('Plan'),
      '#options' => ['free' => $this->t('Free'), 'paid' => $this->t('Paid')],
      '#default_value' => 'free',
    ];
    $form['topics'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Topics'),
      '#options' => ['news' => $this->t('News'), 'events' => $this->t('Events')],
    ];
    // Layout, not a value: the describer must descend through it and neither
    // publish it nor report it as a gap.
    $form['group'] = [
      '#type' => 'details',
      '#title' => $this->t('More'),
      'note' => [
        '#type' => 'textarea',
        '#title' => $this->t('Note'),
      ],
    ];
    // A type FormDescriber has no knowledge of. It is published anyway,
    // because the element plugin describes itself.
    $form['duration'] = [
      '#type' => 'multi_frontend_test_duration',
      '#title' => $this->t('Duration'),
    ];
    // Same name as the field inside $form['group']. Values are flat, so one
    // would silently overwrite the other.
    $form['dupe'] = [
      '#type' => 'container',
      'note' => ['#type' => 'textfield', '#title' => $this->t('Another note')],
    ];
    // Nested values, which a flat schema cannot describe.
    $form['nested'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      'inner' => ['#type' => 'textfield', '#title' => $this->t('Inner')],
    ];
    $form['attachment'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Attachment'),
      '#upload_location' => 'public://test',
    ];
    // Hidden behind access, with a default the handler reports back, so a
    // test can tell whether a client's value was accepted.
    $form['secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret'),
      '#access' => FALSE,
      '#default_value' => 'untouched',
    ];
    // #access as an access result object, which is the form core's own code
    // most often produces, and which a boolean check would miss entirely.
    $form['object_denied'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Denied by object'),
      '#access' => AccessResult::forbidden(),
      '#default_value' => 'untouched',
    ];
    // Options nested under group labels, which a naive read publishes as
    // submittable values.
    $form['region'] = [
      '#type' => 'select',
      '#title' => $this->t('Region'),
      '#options' => [
        'Europe' => ['fi' => $this->t('Finland'), 'se' => $this->t('Sweden')],
        'Americas' => ['us' => $this->t('United States')],
      ],
    ];
    // Numeric option keys, which array_combine() turns into a PHP list.
    $form['rating'] = [
      '#type' => 'select',
      '#title' => $this->t('Rating'),
      '#options' => [0 => $this->t('Poor'), 1 => $this->t('Good')],
    ];
    // FormBuilder refuses input for this, so it is not a writable field.
    $form['locked'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Locked'),
      '#disabled' => TRUE,
      '#default_value' => 'fixed',
    ];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('nickname') === 'taken') {
      $form_state->setErrorByName('nickname', $this->t('That nickname is taken.'));
    }
    if ($form_state->getValue('note') === 'bad') {
      $form_state->setErrorByName('group][note', $this->t('That note is not allowed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('Saved @n.', ['@n' => $form_state->getValue('nickname')]));
    $this->messenger()->addStatus($this->t('Secret is @s.', ['@s' => $form_state->getValue('secret')]));
  }

}
