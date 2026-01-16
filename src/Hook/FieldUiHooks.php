<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Access\CanvasUiAccessCheck;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\Display\EntityDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Hook implementations for Field UI integration.
 */
final class FieldUiHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly CanvasUiAccessCheck $canvasUiAccessCheck,
    private readonly AccountInterface $currentUser,
    private readonly RouteMatchInterface $routeMatch,
    private readonly MessengerInterface $messenger,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $string_translation,
  ) {
    $this->setStringTranslation($string_translation);
  }

  /**
   * Implements hook_form_FORM_ID_alter() for Manage display.
   *
   * Adds a Canvas call-to-action banner as a custom message type on the node
   * "Manage display" page. The CTA encourages users to create content templates
   * using Drupal Canvas instead of the standard "Manage display" table.
   *
   * The CTA is only displayed when:
   * - The user has access to Canvas UI
   * - The current route is the node entity view display edit form
   * - The display entity is for a node content type
   * - No content templates exist for the bundle
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_entity_view_display_edit_form_alter')]
  public function formEntityViewDisplayEditFormAlter(array &$form, FormStateInterface $form_state): void {
    $access_result = $this->canvasUiAccessCheck->access($this->currentUser);
    if (!$access_result->isAllowed()) {
      return;
    }

    // @todo Make this available for other entity types in
    //   https://www.drupal.org/project/canvas/issues/3498525.
    if ($this->routeMatch->getRouteName() !== 'entity.entity_view_display.node.default') {
      return;
    }

    /** @var \Drupal\Core\Entity\EntityFormInterface $form_object */
    $form_object = $form_state->getFormObject();
    $display = $form_object->getEntity();
    if (!$display instanceof EntityDisplayInterface) {
      return;
    }

    $entity_type_id = $display->getTargetEntityTypeId();
    $bundle = $display->getTargetBundle();

    // Check if any content templates exist for this bundle.
    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $existing_templates = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', $entity_type_id)
      ->condition('content_entity_type_bundle', $bundle)
      ->execute();

    // Only show CTA if no content templates exist for this bundle.
    if (!empty($existing_templates)) {
      return;
    }

    $url = Url::fromRoute('canvas.boot.empty', [], [
      'query' => [
        'from' => 'manage_display',
        'entity_type' => $entity_type_id,
        'bundle' => $bundle,
      ],
    ]);

    $cta_build = [
      '#theme' => 'canvas_cta',
      '#icon' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'class' => ['canvas-cta__icon'],
          'aria-hidden' => 'true',
        ],
      ],
      '#title' => $this->t('New: Design with Drupal Canvas'),
      '#description' => $this->t('Canvas provides a visual, drag-and-drop interface for creating content templates. It offers a modern alternative to the standard "Manage display" table.'),
      '#url' => $url->toString(),
      '#link_title' => $this->t('Create with Canvas'),
    ];

    // The custom message type 'canvas_cta' allows us to apply
    // targeted styling without affecting other status messages.
    // @see css/canvas-cta.css
    // @phpstan-ignore-next-line argument.type
    $this->messenger->addMessage($cta_build, 'canvas_cta');
  }

}
