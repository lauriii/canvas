<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\experience_builder\Entity\Page;
use Drupal\experience_builder\EntityHandlers\ContentTemplateAwareViewBuilder;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * @see \Drupal\experience_builder\Entity\ContentTemplate
 * @see \Drupal\experience_builder\EntityHandlers\ContentTemplateAwareViewBuilder
 */
final class ContentTemplateHooks {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * Implements hook_entity_form_display_alter().
   */
  #[Hook('entity_form_display_alter')]
  public function entityFormDisplayAlter(EntityFormDisplayInterface $form_display, array $context): void {
    // @todo Remove this route match check, and instead use
    //   `$context['form_mode']`. This will require refactoring
    //   `\Drupal\experience_builder\Controller\EntityFormController` to pass in a
    //   dynamically generated `xb` form mode.
    if (!\str_starts_with((string) $this->routeMatch->getRouteName(), 'experience_builder.api.')) {
      return;
    }
    $target_entity_type_id = $form_display->getTargetEntityTypeId();
    $entity_type = $this->entityTypeManager->getDefinition($target_entity_type_id);
    \assert($entity_type instanceof EntityTypeInterface);
    if (\is_subclass_of($entity_type->getClass(), EntityPublishedInterface::class) && ($published_key = $entity_type->getKey('published'))) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($target_entity_type_id, $form_display->getTargetBundle());
      // @see \Drupal\experience_builder\InternalXbFieldNameResolver::getXbFieldName()
      $xb_fields = \array_filter($field_definitions, fn(FieldDefinitionInterface $field_definition) => \is_a($field_definition->getItemDefinition()
        ->getClass(), ComponentTreeItem::class, \TRUE));
      if (empty($xb_fields)) {
        return;
      }
      // Publishable entities are automatically published when publishing auto-saved changes.
      // @see \Drupal\experience_builder\Controller\ApiAutoSaveController::post()
      $form_display->removeComponent($published_key);
    }
  }

  /**
   * Implements hook_entity_type_alter.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array $definitions): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
    foreach ($definitions as $entity_type) {
      // XB pages don't have any structured data, and therefore don't support
      // content templates (which require structured data anyway -- that is, they
      // need to be using at least one dynamic prop source).
      // @see docs/adr/0004-page-entity-type.md
      if ($entity_type->id() === Page::ENTITY_TYPE_ID) {
        continue;
      }
      // XB can only render fieldable content entities.
      if ($entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        // @see \Drupal\experience_builder\EntityHandlers\ContentTemplateAwareViewBuilder::createInstance()
        $entity_type->setHandlerClass(ContentTemplateAwareViewBuilder::DECORATED_HANDLER_KEY, $entity_type->getViewBuilderClass())
          ->setViewBuilderClass(ContentTemplateAwareViewBuilder::class);
      }
    }
  }

}
