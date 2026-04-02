<?php

declare(strict_types=1);

namespace Drupal\canvas_test_notifications\Form;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating test notifications.
 */
final class CreateNotificationForm extends FormBase {

  public function __construct(
    private readonly CanvasNotificationHandler $notificationHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(CanvasNotificationHandler::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'canvas_test_notifications_create';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => [
        'info' => 'Info',
        'success' => 'Success',
        'warning' => 'Warning',
        'error' => 'Error',
        'processing' => 'Processing',
      ],
      '#required' => TRUE,
    ];

    $form['key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key'),
      '#description' => $this->t('Optional key for state transitions. Notifications with the same key replace previous processing/error/warning entries.'),
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#required' => TRUE,
    ];

    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
    ];

    $form['actions_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Actions (JSON)'),
      '#description' => $this->t('Optional JSON array of action objects, e.g. <code>[{"label": "View", "href": "/admin"}]</code>'),
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create notification'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $actions_json = trim((string) $form_state->getValue('actions_json'));
    if ($actions_json !== '') {
      $decoded = json_decode($actions_json, TRUE);
      if (!\is_array($decoded)) {
        $form_state->setErrorByName('actions_json', $this->t('Actions must be a valid JSON array.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $notification = [
      'type' => $form_state->getValue('type'),
      'title' => $form_state->getValue('title'),
      'message' => $form_state->getValue('message'),
    ];

    $key = trim((string) $form_state->getValue('key'));
    if ($key !== '') {
      $notification['key'] = $key;
    }

    $actions_json = trim((string) $form_state->getValue('actions_json'));
    if ($actions_json !== '') {
      $notification['actions'] = json_decode($actions_json, TRUE);
    }

    $result = $this->notificationHandler->create($notification);
    $this->messenger()->addStatus($this->t('Notification created with ID: @id', ['@id' => $result['id']]));
  }

}
