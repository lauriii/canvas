<?php

declare(strict_types=1);

namespace Drupal\canvas\Storage;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Handles loading a component tree from entities.
 */
final class ComponentTreeLoader {

  /**
   * Per-request memo of active-exposed-slot bundles, keyed by entity type.
   *
   * @var array<string, string[]>
   */
  private array $activeExposedSlotBundles = [];

  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads a component tree from an entity.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity that stores the component tree. If it does not specifically
   *   implement ComponentTreeEntityInterface, then it is expected to be a
   *   fieldable entity with at least one field that stores a component tree.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
   */
  public function load(ComponentTreeEntityInterface|FieldableEntityInterface $entity): ComponentTreeItemList {
    if ($entity instanceof ComponentTreeEntityInterface) {
      return $entity->getComponentTree();
    }
    $field_name = $this->getCanvasFieldName($entity);
    $item = $entity->get($field_name);
    \assert($item instanceof ComponentTreeItemList);
    return $item;
  }

  /**
   * Gets the Canvas field name from the entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The Canvas field name, or throws an exception
   *   if not found or not supported entity type/bundle.
   *
   * @throws \LogicException
   */
  public function getCanvasFieldName(FieldableEntityInterface $entity): string {
    // @todo Remove this restriction once other entity types and bundles are
    //   allowed in https://drupal.org/i/3498525.
    $articles_allowed_only_on_tests = $entity->getEntityTypeId() === 'node' && $entity->bundle() === 'article' && (drupal_valid_test_ua() || $this->moduleHandler->moduleExists('canvas_test_article_fields'));
    // A templated bundle whose content template exposes at least one active
    // slot stores per-entity slot content in the bundle's Canvas field.
    if (
      $entity->getEntityTypeId() !== Page::ENTITY_TYPE_ID
      && !$articles_allowed_only_on_tests
      && !$this->hasContentTemplateWithExposedSlots($entity)
    ) {
      throw new \LogicException('For now Canvas only works if the entity is a canvas_page! Other entity types and bundles must use content templates for now, see https://drupal.org/i/3498525');
    }

    $map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);

    foreach ($map[$entity->getEntityTypeId()] ?? [] as $field_name => $info) {
      if (\in_array($entity->bundle(), $info['bundles'], TRUE)) {
        return $field_name;
      }
    }
    throw new \LogicException("This entity does not have a Canvas field!");
  }

  /**
   * Whether the bundle has an enabled template with active exposed slots.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose bundle to check.
   *
   * @return bool
   *   TRUE if at least one enabled content template for the entity's type and
   *   bundle exposes at least one active (non-disabled) slot.
   */
  public function hasContentTemplateWithExposedSlots(FieldableEntityInterface $entity): bool {
    return \in_array(
      $entity->bundle(),
      $this->getBundlesWithActiveExposedSlots($entity->getEntityTypeId()),
      TRUE,
    );
  }

  /**
   * Lists the bundles of an entity type with active exposed slots.
   *
   * A bundle qualifies when it has at least one enabled content template that
   * exposes at least one active (non-disabled) slot.
   *
   * @param string $entity_type_id
   *   The content entity type ID.
   *
   * @return string[]
   *   The bundle IDs (values, deduplicated) with active exposed slots. Empty if
   *   the entity type has no such bundle.
   */
  public function getBundlesWithActiveExposedSlots(string $entity_type_id): array {
    if (\array_key_exists($entity_type_id, $this->activeExposedSlotBundles)) {
      return $this->activeExposedSlotBundles[$entity_type_id];
    }
    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('content_entity_type_id', $entity_type_id)
      ->execute();
    if (empty($ids)) {
      return $this->activeExposedSlotBundles[$entity_type_id] = [];
    }
    $bundles = [];
    foreach ($storage->loadMultiple($ids) as $template) {
      \assert($template instanceof ContentTemplate);
      if ($template->status() && !empty($template->getActiveExposedSlots())) {
        $bundles[$template->getTargetBundle()] = $template->getTargetBundle();
      }
    }
    return $this->activeExposedSlotBundles[$entity_type_id] = \array_values($bundles);
  }

}
