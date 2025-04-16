<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
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
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemInstantiatorTrait;

/**
 * Defines a template for content entities in a particular view mode.
 *
 * This MUST be a new config entity type, because doing something like Layout
 * Builder's `LayoutBuilderEntityViewDisplay` is impossible if XB wants to
 * provide a smooth upgrade path from LB, thanks to
 * `\Drupal\layout_builder\Hook\LayoutBuilderHooks::entityTypeAlter()` -- only
 * one module can do that!
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
  ],
)]
final class ContentTemplate extends ConfigEntityBase implements ComponentTreeEntityInterface, EntityViewDisplayInterface {

  use ComponentTreeItemInstantiatorTrait;

  public const string ENTITY_TYPE_ID = 'content_template';

  public const string ADMIN_PERMISSION = 'administer content templates';

  /**
   * ID, composed of content entity type ID + bundle + view mode.
   *
   * @see \Drupal\experience_builder\Plugin\Validation\Constraint\StringPartsConstraint
   */
  protected string $id;

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
   * @var ?array{'inputs': array, 'tree': array}
   */
  protected ?array $component_tree;

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

    // TRICKY: Ideally, dependencies would also be calculated for the `inputs`
    // property. But it can only contain static prop sources at the moment, and
    // those are tracked by the Component config entities that appear in the
    // tree.
    // @todo Ensure that we also add dependencies on field config entities that
    //   are used by dynamic prop sources in https://www.drupal.org/i/3518336.
    $this->addDependencies($this->getComponentTree()->get('tree')->getDependencies());

    // Ensure we depend on the associated view mode.
    $view_mode = $this->getViewMode();
    $this->addDependency($view_mode->getConfigDependencyKey(), $view_mode->getConfigDependencyName());

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentTree(?FieldableEntityInterface $parent = NULL): ComponentTreeItem {
    $item = $this->createDanglingComponentTree($parent);
    $item->setValue($this->component_tree);
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
    return $this->getComponentTree($entity)->toRenderable();
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

}
