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
   * Per-request memo of exposed-slot bundles, keyed by entity type.
   *
   * @var array<string, string[]>
   */
  private array $exposedSlotBundles = [];

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
   * Loads every component tree stored on an entity.
   *
   * The multi-field counterpart to ::load(): for a ComponentTreeEntityInterface
   * (canvas_page) or a single-field entity this is one list; for a templated
   * bundle it is one list per `component_tree` field (one per exposed slot).
   * Unlike ::load(), it never throws for multi-field entities, so callers that
   * can run on templated entities (finding/updating a component instance,
   * extracting inputs, reconciling versions on publish) use this instead of the
   * single-field ::load()/::getCanvasFieldName().
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList[]
   *   The entity's component tree lists (empty if it has none).
   */
  public function loadAll(ComponentTreeEntityInterface|FieldableEntityInterface $entity): array {
    if ($entity instanceof ComponentTreeEntityInterface) {
      return [$entity->getComponentTree()];
    }
    $map = $this->entityFieldManager->getFieldMapByFieldType(ComponentTreeItem::PLUGIN_ID);
    $lists = [];
    foreach ($map[$entity->getEntityTypeId()] ?? [] as $field_name => $info) {
      if (\in_array($entity->bundle(), $info['bundles'], TRUE) && $entity->hasField($field_name)) {
        $item = $entity->get($field_name);
        \assert($item instanceof ComponentTreeItemList);
        $lists[] = $item;
      }
    }
    return $lists;
  }

  /**
   * Finds the component tree list on an entity that holds a component instance.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeEntityInterface|\Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $componentInstanceUuid
   *   The component instance UUID to locate.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList|null
   *   The list containing the instance, or NULL if none does.
   */
  public function findItemListContaining(ComponentTreeEntityInterface|FieldableEntityInterface $entity, string $componentInstanceUuid): ?ComponentTreeItemList {
    foreach ($this->loadAll($entity) as $list) {
      if ($list->getComponentTreeItemByUuid($componentInstanceUuid) !== NULL) {
        return $list;
      }
    }
    return NULL;
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
    // Only single-field entities (canvas_page, and article on tests) resolve to
    // a single Canvas field. Templated bundles store per-entity slot content in
    // one `component_tree` field per exposed slot, addressed directly by the
    // slot's machine name (the `exposed_slots` key), not through this method.
    if (
      $entity->getEntityTypeId() !== Page::ENTITY_TYPE_ID
      && !$articles_allowed_only_on_tests
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
   * Whether the bundle has an enabled template with exposed slots.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity whose bundle to check.
   *
   * @return bool
   *   TRUE if at least one enabled content template for the entity's type and
   *   bundle exposes at least one slot.
   */
  public function hasContentTemplateWithExposedSlots(FieldableEntityInterface $entity): bool {
    return \in_array(
      $entity->bundle(),
      $this->getBundlesWithExposedSlots($entity->getEntityTypeId()),
      TRUE,
    );
  }

  /**
   * Lists the bundles of an entity type with exposed slots.
   *
   * A bundle qualifies when it has at least one enabled content template that
   * exposes at least one slot.
   *
   * @param string $entity_type_id
   *   The content entity type ID.
   *
   * @return string[]
   *   The bundle IDs (values, deduplicated) with exposed slots. Empty if the
   *   entity type has no such bundle.
   */
  public function getBundlesWithExposedSlots(string $entity_type_id): array {
    if (\array_key_exists($entity_type_id, $this->exposedSlotBundles)) {
      return $this->exposedSlotBundles[$entity_type_id];
    }
    $storage = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID);
    $enabled_templates = $storage->loadByProperties([
      'content_entity_type_id' => $entity_type_id,
      'status' => TRUE,
    ]);
    $bundles = [];
    foreach ($enabled_templates as $template) {
      \assert($template instanceof ContentTemplate);
      if (!empty($template->getExposedSlots())) {
        $bundles[$template->getTargetBundle()] = $template->getTargetBundle();
      }
    }
    return $this->exposedSlotBundles[$entity_type_id] = \array_values($bundles);
  }

}
