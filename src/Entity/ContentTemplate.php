<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewModeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\experience_builder\EntityHandlers\ContentCreatorVisibleXbConfigEntityAccessControlHandler;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Storage\ComponentTreeLoader;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemListInstantiatorTrait;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Defines a template for content entities in a particular view mode.
 *
 * This MUST be a new config entity type, because doing something like Layout
 * Builder's `LayoutBuilderEntityViewDisplay` is impossible if XB wants to
 * provide a smooth upgrade path from LB, thanks to
 * `\Drupal\layout_builder\Hook\LayoutBuilderHooks::entityTypeAlter()` -- only
 * one module can do that!
 *
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ExposedSlotDefinitions from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 */
#[ConfigEntityType(
  id: self::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Content template'),
  label_collection: new TranslatableMarkup('Content templates'),
  label_singular: new TranslatableMarkup('content template'),
  label_plural: new TranslatableMarkup('content templates'),
  entity_keys: [
    'id' => 'id',
  ],
  handlers: [
    'access' => ContentCreatorVisibleXbConfigEntityAccessControlHandler::class,
  ],
  admin_permission: self::ADMIN_PERMISSION,
  constraints: [
    'ImmutableProperties' => [
      'id',
      'content_entity_type_id',
      'content_entity_type_bundle',
      'content_entity_type_view_mode',
    ],
  ],
  config_export: [
    'id',
    'content_entity_type_id',
    'content_entity_type_bundle',
    'content_entity_type_view_mode',
    'component_tree',
    'exposed_slots',
  ],
)]
final class ContentTemplate extends ConfigEntityBase implements ComponentTreeEntityInterface, EntityViewDisplayInterface {

  use ComponentTreeItemListInstantiatorTrait;

  public const string ENTITY_TYPE_ID = 'content_template';

  public const string ADMIN_PERMISSION = 'administer content templates';

  /**
   * ID, composed of content entity type ID + bundle + view mode.
   *
   * @see \Drupal\experience_builder\Plugin\Validation\Constraint\StringPartsConstraint
   */
  protected ?string $id;

  /**
   * Entity type to be displayed.
   *
   * @var string|null
   */
  protected ?string $content_entity_type_id;

  /**
   * Bundle to be displayed.
   *
   * @var string|null
   */
  protected ?string $content_entity_type_bundle;

  /**
   * View or mode to be displayed.
   *
   * @var string|null
   */
  protected ?string $content_entity_type_view_mode;

  /**
   * The component tree.
   *
   * @var ?array<string, ComponentTreeItemArray>
   */
  protected ?array $component_tree;

  /**
   * The exposed slots.
   *
   * @var ?array<string, array{'component_uuid': string, 'slot_name': string, 'label': string}>
   */
  protected ?array $exposed_slots = [];

  /**
   * Tries to load a template for a particular entity, in a specific view mode.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   An entity, presumably the one being viewed.
   * @param string $view_mode
   *   The view mode in which we're viewing the entity.
   *
   * @return self|null
   *   A template for the given entity in the given view mode, or NULL if one
   *   does not exist.
   */
  public static function loadForEntity(FieldableEntityInterface $entity, string $view_mode): ?self {
    $id = implode('.', [
      $entity->getEntityTypeId(),
      $entity->bundle(),
      $view_mode,
    ]);
    return self::load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return $this->content_entity_type_id . '.' . $this->content_entity_type_bundle . '.' . $this->content_entity_type_view_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    $this->id = $this->id();
    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    $entity_type = $this->entityTypeManager()
      ->getDefinition($this->getTargetEntityTypeId());
    assert($entity_type instanceof EntityTypeInterface);

    $bundle_info = \Drupal::service(EntityTypeBundleInfoInterface::class)
      ->getBundleInfo($entity_type->id());
    $bundle = $this->getTargetBundle();

    $variables = [
      '@entities' => $entity_type->getCollectionLabel(),
      '@mode' => $this->getViewMode()->label(),
    ];

    if ($entity_type->getBundleEntityType()) {
      $variables['@entities'] = $entity_type->getPluralLabel();
      $variables['@bundle'] = $bundle_info[$bundle]['label'] ?? throw new \RuntimeException("The '$bundle' bundle of the {$entity_type->id()} entity type has no label.");
      return new TranslatableMarkup('@bundle @entities — @mode view', $variables);
    }
    return new TranslatableMarkup('@entities — @mode view', $variables);
  }

  /**
   * Gets the view mode that this template is for.
   *
   * @return \Drupal\Core\Entity\EntityViewModeInterface
   *   The view mode entity.
   */
  private function getViewMode(): EntityViewModeInterface {
    $view_mode = $this->entityTypeManager()
      ->getStorage('entity_view_mode')
      ->load($this->getTargetEntityTypeId() . '.' . $this->getMode());
    assert($view_mode instanceof EntityViewModeInterface);
    return $view_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): static {
    parent::calculateDependencies();

    $this->addDependencies($this->getComponentTree()->calculateDependencies());

    // Ensure we depend on the associated view mode.
    $view_mode = $this->getViewMode();
    $this->addDependency($view_mode->getConfigDependencyKey(), $view_mode->getConfigDependencyName());

    return $this;
  }

  /**
   * Returns information about the slots exposed by this template.
   *
   * @return array<string, array{'component_uuid': string, 'slot_name': string, 'label': string}>
   */
  public function getExposedSlots(): array {
    return $this->get('exposed_slots') ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(?FieldableEntityInterface $parent = NULL): ComponentTreeItemList {
    $item = $this->createDanglingComponentTreeItemList($parent);
    $item->setValue(\array_values($this->component_tree ?? []));
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function createCopy($view_mode): never {
    throw new \BadMethodCallException(__METHOD__ . '() is not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function getComponents(): array {
    // A linear list of "components", where each component is a field formatter,
    // doesn't make sense when using XB.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent($name): null {
    // @see ::getComponents()
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setComponent($name, array $options = []): never {
    throw new \LogicException(__FUNCTION__ . '() does not make sense for content templates. The calling could should be updated to check for this.');
  }

  /**
   * {@inheritdoc}
   */
  public function removeComponent($name): never {
    throw new \LogicException(__FUNCTION__ . '() does not make sense for content templates. The calling could should be updated to check for this.');
  }

  /**
   * {@inheritdoc}
   */
  public function getHighestWeight(): null {
    // @see ::getComponents()
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getRenderer($field_name): null {
    // @see ::getComponents()
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): string {
    return (string) $this->content_entity_type_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getMode(): string {
    return (string) $this->content_entity_type_view_mode;
  }

  /**
   * {@inheritdoc}
   */
  public function getOriginalMode(): never {
    throw new \BadMethodCallException(__METHOD__ . '() is not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetBundle(): string {
    return (string) $this->content_entity_type_bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function setTargetBundle($bundle): static {
    return $this->set('bundle', $bundle);
  }

  /**
   * {@inheritdoc}
   */
  public function build(FieldableEntityInterface $entity): array {
    // The entity should not be able to expose its own full, independently
    // renderable component tree -- if it can, why is it even using a template?
    if ($entity instanceof ComponentTreeEntityInterface) {
      throw new \LogicException('Content templates cannot be applied to entities that have their own component trees.');
    }

    // The entity is *expected* to have an XB field, or it's not considered
    // opted in to XB. An entity that isn't opted into XB should never be passed
    // to this method, so we don't need to catch the possible exception here.
    $xb_field_name = \Drupal::service(ComponentTreeLoader::class)
      ->getXbFieldName($entity);

    $sub_tree_item_list = $entity->get($xb_field_name);
    \assert($sub_tree_item_list instanceof ComponentTreeItemList);
    return $this->getComponentTree($entity)
      ->injectSubTreeItemList($this->getExposedSlots(), $sub_tree_item_list)
      ->toRenderable($this);
  }

  /**
   * {@inheritdoc}
   */
  public function buildMultiple(array $entities): array {
    return array_map($this->build(...), $entities);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections(): array {
    // Normally, this would be a collection of field formatter instances, but
    // that doesn't make sense when using XB.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function onDependencyRemoval(array $dependencies): bool {
    $changed = FALSE;
    $tree = $this->getComponentTree();

    foreach ($dependencies as $type => $dependencies_of_type) {
      foreach ($dependencies_of_type as $dependency) {
        if ($dependency instanceof ConfigEntityInterface) {
          $dependency = $dependency->getConfigDependencyName();
        }
        foreach ($tree as $item) {
          \assert($item instanceof ComponentTreeItem);
          $changed |= $item->updatePropSourcesOnDependencyRemoval($type, $dependency);
        }
      }
    }
    if ($changed) {
      $this->set('component_tree', $tree->getValue());
    }

    $changed |= parent::onDependencyRemoval($dependencies);
    return (bool) $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function set($property_name, $value): self {
    if ($property_name === 'component_tree') {
      // Ensure predictable order of tree items.
      $value = self::generateComponentTreeKeys($value);
    }
    return parent::set($property_name, $value);
  }

}
