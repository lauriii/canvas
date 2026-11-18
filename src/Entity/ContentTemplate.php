<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\canvas\ClientSideRepresentation;
use Drupal\canvas\EntityHandlers\VisibleWhenDisabledCanvasConfigEntityAccessControlHandler;
use Drupal\canvas\Exception\SubtreeInjectionException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewModeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Utility\Error;
use Drupal\field\Entity\FieldConfig;

/**
 * Defines a template for content entities in a particular view mode.
 *
 * This MUST be a new config entity type, because doing something like Layout
 * Builder's `LayoutBuilderEntityViewDisplay` is impossible if Canvas wants to
 * provide a smooth upgrade path from LB, thanks to
 * `\Drupal\layout_builder\Hook\LayoutBuilderHooks::entityTypeAlter()` -- only
 * one module can do that!
 *
 * @phpstan-import-type ExposedSlotDefinitions from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
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
    'access' => VisibleWhenDisabledCanvasConfigEntityAccessControlHandler::class,
  ],
  admin_permission: self::ADMIN_PERMISSION,
  constraints: [
    'ImmutableProperties' => [
      'properties' => [
        'id',
        'content_entity_type_id',
        'content_entity_type_bundle',
        'content_entity_type_view_mode',
      ],
    ],
  ],
  config_export: [
    'id',
    'content_entity_type_id',
    'content_entity_type_bundle',
    'content_entity_type_view_mode',
    'component_tree',
    'exposed_slots',
    'page_variant',
  ],
)]
final class ContentTemplate extends ComponentTreeConfigEntityBase implements CanvasHttpApiEligibleConfigEntityInterface, EmptyTargetEntityProviderInterface, EntityViewDisplayInterface, AutoSavePublishAwareInterface {

  public const string ENTITY_TYPE_ID = 'content_template';

  public const string ADMIN_PERMISSION = 'administer content templates';

  /**
   * ID, composed of content entity type ID + bundle + view mode.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\StringPartsConstraint
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
   * The exposed slots, keyed by the machine name of the backing field.
   *
   * @var ?array<string, array{'component_uuid': string, 'slot_name': string, 'label': string}>
   */
  protected ?array $exposed_slots = [];

  /**
   * The machine name of the selected page variant, or NULL for the default.
   */
  protected ?string $page_variant = NULL;

  /**
   * Disabled by default.
   *
   * @var bool
   */
  protected $status = FALSE;

  /**
   * Builds the ID of the template for an entity type, bundle and view mode.
   *
   * @param string $entity_type_id
   *   A content entity type ID.
   * @param string $bundle
   *   A bundle of that entity type. For entity types without bundles, this is
   *   the entity type ID.
   * @param string $view_mode
   *   A view mode machine name.
   *
   * @return string
   *   The corresponding template ID, whether or not such a template exists.
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\StringPartsConstraint
   */
  public static function createId(string $entity_type_id, string $bundle, string $view_mode): string {
    return \implode('.', [$entity_type_id, $bundle, $view_mode]);
  }

  /**
   * Tries to load the template for an entity type, bundle and view mode.
   *
   * @param string $entity_type_id
   *   A content entity type ID.
   * @param string $bundle
   *   A bundle of that entity type.
   * @param string $view_mode
   *   A view mode machine name.
   *
   * @return self|null
   *   The template, or NULL if none exists.
   */
  public static function loadFor(string $entity_type_id, string $bundle, string $view_mode): ?self {
    return self::load(self::createId($entity_type_id, $bundle, $view_mode));
  }

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
    // Entity types without bundles return their entity type ID from ::bundle().
    return self::loadFor($entity->getEntityTypeId(), $entity->bundle(), $view_mode);
  }

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return self::createId(
      (string) $this->content_entity_type_id,
      (string) $this->content_entity_type_bundle,
      (string) $this->content_entity_type_view_mode,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    $this->id = $this->id();
    // Prune exposed slots whose target component is gone. Before
    // parent::preSave() calculates dependencies, or deleting a pruned slot's
    // field would delete the whole template.
    if (!$this->isSyncing()) {
      $exposed_slots = $this->getExposedSlots();
      $tree = $this->getComponentTree();
      $kept = \array_filter(
        $exposed_slots,
        static fn (array $definition): bool => isset($definition['component_uuid']) && $tree->getComponentTreeItemByUuid($definition['component_uuid']) !== NULL,
      );
      if (\count($kept) !== \count($exposed_slots)) {
        $this->set('exposed_slots', $kept);
      }
    }
    parent::preSave($storage);
    if ($this->isSyncing() && self::getConfigUpdater()->needsIntermediateDependenciesComponentUpdate($this)) {
      // We might need to update dependencies even on import.
      // @see \canvas_post_update_0002_intermediate_component_dependencies_in_content_templates()
      $this->calculateDependencies();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    $entity_type = $this->entityTypeManager()
      ->getDefinition($this->getTargetEntityTypeId());
    \assert($entity_type instanceof EntityTypeInterface);

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
    \assert($view_mode instanceof EntityViewModeInterface);
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

    // Each exposed slot is backed by a `component_tree` field on the bundle,
    // keyed by that field's machine name. Depend on those field configs so
    // deleting a field through Field UI detaches the slot via
    // ::onDependencyRemoval().
    $entity_type_id = $this->getTargetEntityTypeId();
    $bundle = $this->getTargetBundle();
    foreach (\array_keys($this->getExposedSlots()) as $field_name) {
      $field_config = FieldConfig::loadByName($entity_type_id, $bundle, $field_name);
      if ($field_config?->getType() === ComponentTreeItem::PLUGIN_ID) {
        $this->addDependency($field_config->getConfigDependencyKey(), $field_config->getConfigDependencyName());
      }
    }

    // Depend on the selected page variant, if any. Deleting that variant
    // triggers ::onDependencyRemoval() to drop the selection rather than
    // cascade-deleting this template.
    if ($this->page_variant !== NULL) {
      $variant = PageVariant::load($this->page_variant);
      if ($variant !== NULL) {
        $this->addDependency($variant->getConfigDependencyKey(), $variant->getConfigDependencyName());
      }
    }

    return $this;
  }

  /**
   * Returns the machine name of the selected page variant, or NULL.
   */
  public function getPageVariant(): ?string {
    return $this->page_variant;
  }

  /**
   * Returns information about the slots exposed by this template.
   *
   * Keyed by the machine name of the `component_tree` field backing each slot.
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
    $item = $this->createDanglingComponentTreeItemList($parent ?? $this);
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
    // doesn't make sense when using Canvas.
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

  public function getTargetEntityDataDefinition(): EntityDataDefinitionInterface {
    return EntityDataDefinition::create(
      $this->getTargetEntityTypeId(),
      $this->getTargetBundle(),
    );
  }

  public function createEmptyTargetEntity(): FieldableEntityInterface {
    $entity_type_manager = \Drupal::entityTypeManager();
    $storage = $entity_type_manager->getStorage($this->getTargetEntityTypeId());
    $content_entity_type = $entity_type_manager->getDefinition($this->getTargetEntityTypeId());
    \assert($content_entity_type instanceof ContentEntityTypeInterface);

    $empty_target_entity = $content_entity_type->hasKey('bundle')
      ? $storage->create([
        $content_entity_type->getKey('bundle') => $this->getTargetBundle(),
      ])
      : $storage->create();
    \assert($empty_target_entity instanceof FieldableEntityInterface);

    return $empty_target_entity;
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
   *
   * @param array<string, true> $suppressAnnotationsFor
   *   Component instance UUIDs, keyed by UUID, to render in preview without
   *   the client-side annotation comments.
   */
  public function build(FieldableEntityInterface $entity, bool $isPreview = FALSE, array $suppressAnnotationsFor = []): array {
    // The entity should not be able to expose its own full, independently
    // renderable component tree -- if it can, why is it even using a template?
    if ($entity instanceof ComponentTreeEntityInterface) {
      throw new \LogicException('Content templates cannot be applied to entities that have their own component trees.');
    }

    // The template replaces field formatters, so it honors field-level view
    // access the way formatter-rendered fields do, and the access results'
    // cacheability must end up in the render array.
    $cacheability = new CacheableMetadata();
    $build = $this->getMergedComponentTree($entity, $cacheability)->toRenderable($this, $isPreview, $suppressAnnotationsFor);
    $cacheability->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
    return $build;
  }

  /**
   * Gets the effective component tree for an entity rendered by this template.
   *
   * The template's tree with each exposed slot's per-entity content merged
   * into its target slot.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity the template renders.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $cacheability
   *   Receives the cacheability of the field access checks.
   * @param bool $check_access
   *   Whether to enforce field-level view access: a slot field the user may
   *   not view renders the template default (mirroring how formatters skip
   *   view-denied fields). Only access-independent consumers (search indexing)
   *   pass FALSE.
   */
  public function getMergedComponentTree(FieldableEntityInterface $entity, RefinableCacheableDependencyInterface $cacheability, bool $check_access = TRUE): ComponentTreeItemList {
    $merged_tree = $this->getComponentTree($entity);
    // Each exposed slot is backed by its own `component_tree` field on the
    // bundle; merge each field's content into the template's target slot. A
    // slot whose backing field is missing (e.g. deleted, or not yet created)
    // renders the template's own default.
    foreach ($this->getExposedSlots() as $field_name => $definition) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $slot_field = $entity->get($field_name);
      \assert($slot_field instanceof ComponentTreeItemList);
      if ($check_access) {
        $access = $slot_field->access('view', NULL, TRUE);
        $cacheability->addCacheableDependency($access);
        if (!$access->isAllowed()) {
          continue;
        }
      }
      try {
        $merged_tree->injectSlotContent($definition['component_uuid'], $definition['slot_name'], $slot_field);
      }
      catch (SubtreeInjectionException $e) {
        // Entity validation rejects UUID collisions, but not every write path
        // validates. Render the template default instead of failing the page.
        // @see \Drupal\canvas\Plugin\Validation\Constraint\ExposedSlotContentIsMergeableConstraint
        Error::logException(\Drupal::logger('canvas'), $e);
      }
    }
    return $merged_tree;
  }

  /**
   * {@inheritdoc}
   */
  public function buildMultiple(array $entities): array {
    // EntityViewBuilder::buildComponents() merges each build into the entity's
    // build defaults with `+=`, which drops this build's top-level #cache. Nest
    // each build one level deeper, so its cacheability bubbles up.
    // @see \Drupal\Core\Entity\EntityViewBuilder::buildComponents()
    return \array_map(fn (FieldableEntityInterface $entity): array => ['content_template' => $this->build($entity)], $entities);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections(): array {
    // Normally, this would be a collection of field formatter instances, but
    // that doesn't make sense when using Canvas.
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
      $this->setComponentTree($tree->getValue());
    }

    // Detach exposed slots whose backing `component_tree` field config is being
    // removed: drop the definition, leaving the template valid. Entity content
    // is not touched (the field API purges it), matching the editor's detach
    // semantics.
    $removed_config_names = [];
    foreach ($dependencies['config'] ?? [] as $dependency) {
      $removed_config_names[$dependency instanceof ConfigEntityInterface ? $dependency->getConfigDependencyName() : $dependency] = TRUE;
    }
    if ($removed_config_names !== []) {
      $entity_type_id = $this->getTargetEntityTypeId();
      $bundle = $this->getTargetBundle();
      $exposed_slots = $this->getExposedSlots();
      $kept = \array_filter(
        $exposed_slots,
        static fn (array $definition, string $field_name): bool => !isset($removed_config_names["field.field.$entity_type_id.$bundle.$field_name"]),
        ARRAY_FILTER_USE_BOTH,
      );
      if (\count($kept) !== \count($exposed_slots)) {
        $this->set('exposed_slots', $kept);
        $changed = TRUE;
      }
    }

    // Drop a stale page variant selection instead of letting the config
    // dependency system cascade-delete this template when the selected variant
    // is removed.
    if ($this->page_variant !== NULL && isset($dependencies['config'])) {
      $variant_config_name = 'canvas.' . PageVariant::ENTITY_TYPE_ID . '.' . $this->page_variant;
      foreach ($dependencies['config'] as $dependency) {
        $name = $dependency instanceof ConfigEntityInterface ? $dependency->getConfigDependencyName() : $dependency;
        if ($name === $variant_config_name) {
          $this->set('page_variant', NULL);
          $changed = TRUE;
          break;
        }
      }
    }

    $changed |= parent::onDependencyRemoval($dependencies);
    return (bool) $changed;
  }

  /**
   * {@inheritdoc}
   */
  public function autoSavePublish(): self {
    $this->setStatus(TRUE);
    return $this;
  }

  public function normalizeForClientSide(): ClientSideRepresentation {
    $entity_type_manager = $this->entityTypeManager();
    \assert(\is_string($this->content_entity_type_id));
    $content_entity_type = $entity_type_manager->getDefinition($this->content_entity_type_id);
    $storage = $entity_type_manager->getStorage($this->getTargetEntityTypeId());

    // Determine the preview entity (if any), and ensure correct cacheability:
    // - for the query in ::getSuggestedPreviewEntity()
    // - for the access checking here
    $preview_entity_cacheability = (new CacheableMetadata())
      ->addCacheContexts($storage->getEntityType()->getListCacheContexts())
      ->addCacheTags($storage->getEntityType()->getBundleListCacheTags($this->getTargetBundle()));
    $preview_entity = $this->getSuggestedPreviewEntity($preview_entity_cacheability);

    // Normalize component tree for API consumers.
    $component_tree = [];
    foreach ($this->getComponentTree() as $item) {
      \assert($item instanceof ComponentTreeItem);
      $values = \array_map(fn($property) => $property->getValue(), $item->getProperties(TRUE));
      unset($values['parent_item'], $values['component']);
      $values['inputs'] = $item->getInputs();
      $component_tree[] = $values;
    }

    return ClientSideRepresentation::create(
      values: [
        'entityType' => $this->content_entity_type_id,
        'bundle' => $this->content_entity_type_bundle,
        'viewMode' => $this->content_entity_type_view_mode,
        'viewModeLabel' => $this->getViewMode()->label(),
        'label' => $this->label(),
        'status' => $this->status,
        'id' => $this->id(),
        'suggestedPreviewEntityId' => $preview_entity ? self::normalizeEntityId($preview_entity) : NULL,
        'pageVariant' => $this->page_variant,
        'component_tree' => $component_tree,
        // Keyed by backing field machine name; the editor and CLI manage them
        // as opaque slot keys.
        'exposed_slots' => $this->getExposedSlots(),
      ],
      preview: NULL,
    )
      ->addCacheableDependency($preview_entity_cacheability)
      // Cacheability metadata for the suggested preview entity.
      ->addCacheContexts($content_entity_type->getListCacheContexts())
      // @phpstan-ignore-next-line argument.type
      ->addCacheTags($content_entity_type->getBundleListCacheTags($this->content_entity_type_bundle));
  }

  public static function createFromClientSide(array $data): static {
    ['entityType' => $entity_type, 'bundle' => $bundle, 'viewMode' => $view_mode] = $data;
    return self::create([
      'id' => self::createId($entity_type, $bundle, $view_mode),
      'content_entity_type_id' => $entity_type,
      'content_entity_type_bundle' => $bundle,
      'content_entity_type_view_mode' => $view_mode,
      'component_tree' => $data['component_tree'] ?? [],
      'exposed_slots' => $data['exposed_slots'] ?? [],
      'status' => $data['status'] ?? FALSE,
      // Optional, so that a template can be created with its page template
      // already selected; omitting it falls back to the site default variant.
      // Anything that is not a non-empty string means "no selection": the
      // property is typed, so passing a non-string through would be a fatal
      // error rather than the validation error a client deserves.
      // @see ::updateFromClientSide()
      'page_variant' => \is_string($data['pageVariant'] ?? NULL) && $data['pageVariant'] !== ''
        ? $data['pageVariant']
        : NULL,
    ]);
  }

  public function updateFromClientSide(array $data): void {
    // The Canvas UI's editor frame edits content templates indirectly via the
    // Layout API and the auto-save flow; PATCH on this config endpoint is for
    // external consumers (CLI) that write the persisted state directly.
    // @see \Drupal\canvas\Controller\ApiLayoutController::patch()
    if (\array_key_exists('component_tree', $data)) {
      $this->set('component_tree', $data['component_tree']);
    }
    if (\array_key_exists('exposed_slots', $data)) {
      $this->set('exposed_slots', $data['exposed_slots']);
    }
    if (\array_key_exists('status', $data)) {
      $this->set('status', (bool) $data['status']);
    }
    if (\array_key_exists('pageVariant', $data)) {
      // Anything that is not a non-empty string means "no selection": the
      // property is typed, so passing a non-string through would be a fatal
      // error rather than the validation error a client deserves.
      // @see ::createFromClientSide()
      $this->set('page_variant', \is_string($data['pageVariant']) && $data['pageVariant'] !== ''
        ? $data['pageVariant']
        : NULL);
    }
  }

  public static function refineListQuery(QueryInterface &$query, RefinableCacheableDependencyInterface $cacheability): void {
    // Nothing to do.
  }

  public static function getPreviewSuggestionQuery(string $entity_type_id, string $bundle, int $limit): QueryInterface {
    $entity_type_manager = \Drupal::entityTypeManager();
    $entity_definition = $entity_type_manager->getDefinition($entity_type_id);

    $id_key = $entity_definition->getKey('id');
    \assert(\is_string($id_key));
    $entity_query = $entity_type_manager->getStorage($entity_type_id)->getQuery()
      ->accessCheck(TRUE)
      ->range(0, $limit);
    if ($entity_definition->hasKey('bundle')) {
      $bundle_key = $entity_definition->getKey('bundle');
      \assert(\is_string($bundle_key));
      $entity_query->condition($bundle_key, $bundle);
    }

    // @todo Remove conditionality in https://www.drupal.org/i/3498525
    if ($entity_definition->entityClassImplements(EntityChangedInterface::class)) {
      $entity_query->sort('changed', 'DESC');
    }
    // Tie-break on ID, so the order is deterministic.
    $entity_query->sort($id_key, 'DESC');
    return $entity_query;
  }

  /**
   * Returns the entity's ID as an integer if its ID field is an integer field.
   *
   * Entity storage returns integer IDs as strings.
   */
  private static function normalizeEntityId(ContentEntityInterface $entity): int|string {
    $id = $entity->id();
    \assert(\is_int($id) || \is_string($id));
    $id_key = $entity->getEntityType()->getKey('id');
    \assert(\is_string($id_key));
    return $entity->getFieldDefinition($id_key)?->getType() === 'integer' ? (int) $id : $id;
  }

  /**
   * Suggests the most recently changed entity the current user can view.
   *
   * The most recently changed entity is not necessarily viewable: for example,
   * the anonymous user's profile never is, and an unpublished node may not be.
   * Checks up to 10 candidates, like ::suggestPreviewContentEntities() lists.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Receives the cacheability of the access checks performed.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The suggested preview entity, or NULL if there is no viewable one.
   *
   * @see \Drupal\canvas\Controller\ApiUiContentTemplateControllers::suggestPreviewContentEntities()
   */
  private function getSuggestedPreviewEntity(CacheableMetadata $cacheability): ?ContentEntityInterface {
    \assert($this->content_entity_type_id !== NULL);

    $results = self::getPreviewSuggestionQuery(
      $this->getTargetEntityTypeId(),
      $this->getTargetBundle(),
      10
    )->execute();
    \assert(\is_array($results));

    $entities = $this->entityTypeManager()
      ->getStorage($this->getTargetEntityTypeId())
      ->loadMultiple($results);
    foreach ($entities as $entity) {
      \assert($entity instanceof ContentEntityInterface);
      $access = $entity->access('view', return_as_object: TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        return $entity;
      }
    }
    return NULL;
  }

}
