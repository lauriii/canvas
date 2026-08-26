<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave;

use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Controller\ApiContentControllers;
use Drupal\canvas\Controller\ConflictResolutionOutcomeEnum;
use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\canvas\Entity\ComponentTreeConfigEntityBase;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas\Entity\StagedConfigUpdate;
use Drupal\canvas\Entity\StagedLanguageConfigOverride;
use Drupal\canvas\Health\HealthCheck;
use Drupal\canvas\Health\HealthRecords;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\SortArray;
use Drupal\content_moderation\Plugin\Field\ModerationStateFieldItemList;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\path\Plugin\Field\FieldType\PathFieldItemList;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a class for storing and retrieving auto-save data.
 *
 * Auto-save data is stored forever in the key-value store. So in principle, it
 * can grow forever.
 * However, auto-save entries for a (content or config) entity are deleted when:
 * - publishing an entity's auto-save entry
 * - deleting an entity
 *
 * @see \Drupal\canvas\Controller\ApiAutoSaveController::post()
 * @see \Drupal\canvas\Hook\AutoSaveHooks::entityDelete()
 *
 * @phpstan-type ConflictId string
 * @phpstan-type AutoSaveEntry array{data: array, owner: int, updated: int, entity_type: string, entity_id: string|int, label: string, original_hash: string, data_hash: string, client_id: ?string, langcode: ?string, is_default_translation: bool, entity: ?EntityInterface, conflict_id?: string}
 */
class AutoSaveManager implements EventSubscriberInterface {

  public const CACHE_TAG = 'canvas__auto_save';
  public const string PUBLISH_PERMISSION = 'publish auto-saves';
  public const string AUTO_SAVE_STORE = 'canvas.auto_save';
  public const string AUTO_SAVE_CONFLICT_KEY = 'conflict_id';
  public const string AUTO_SAVE_STORED_ENTITY_HASH_KEY = 'original_hash';
  public const string FORM_VIOLATIONS_STORE = 'canvas.form_violations';
  public const string COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE = 'canvas.component_instance_form_violations';

  /**
   * Various internal auto-save properties that are not used client side.
   *
   * The 'client_id' is only used to determine if the client has the latest
   * changes when editing an entity in Drupal Canvas and not needed for the
   * publishing process.
   *
   * The 'conflict_id' key is not persisted in the store; it is added
   * dynamically by ::getAllAutoSaveList() when $with_conflicts is TRUE and
   * stripped here before the data is returned to the client.
   */
  public const array AUTO_SAVE_INTERNAL_PROPERTIES = [
    'data',
    'client_id',
    'entity',
    self::AUTO_SAVE_STORED_ENTITY_HASH_KEY,
    self::AUTO_SAVE_CONFLICT_KEY,
    'is_default_translation',
  ];
  const ENTITY_DUPLICATE_SUFFIX = ' (Copy)';

  private KeyValueStoreInterface $autoSaveStore;

  /**
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  private KeyValueStoreInterface $formViolationsStore;

  /**
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  private KeyValueStoreInterface $componentInstanceFormViolationsStore;

  public function __construct(
    private readonly ConfigManagerInterface $configManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    // A non-serializing in-memory cache. ::getAutoSaveEntity() caches a live
    // entity object here; a serializing backend (e.g. cache.static) would run
    // the entity's ::__sleep(), forcing computed fields (such as metatag's) to
    // compute mid-cache-write. That can re-enter ::getAutoSaveEntity() — e.g.
    // via the `[current-page:title]` token resolving the layout route's title
    // callback (ApiLayoutController::getLabel) — and recurse infinitely.
    // metatag only computes for non-new entities, so this surfaced once
    // auto-save entities became non-new via ::createEntityFromAutoSaveEntry().
    // @see \Drupal\metatag\Plugin\Field\MetatagEntityFieldItemList::computeValue()
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $cache,
    #[Autowire(service: 'keyvalue')]
    KeyValueFactoryInterface $keyValueFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly HealthRecords $healthRecords,
  ) {
    $this->autoSaveStore = $keyValueFactory->get(self::AUTO_SAVE_STORE);
    $this->formViolationsStore = $keyValueFactory->get(self::FORM_VIOLATIONS_STORE);
    $this->componentInstanceFormViolationsStore = $keyValueFactory->get(self::COMPONENT_INSTANCE_FORM_VIOLATIONS_STORE);
  }

  /**
   * Checks whether a computed field is user-editable and persisted on save.
   *
   * Some fields are marked as computed because their values are not stored in
   * the host entity's tables, but they are user-editable and persisted by
   * their own storage on entity save:
   * - `path`: persisted as `path_alias` entities by PathItem::postSave().
   * - `moderation_state`: persisted as `content_moderation_state` entities.
   * These must be treated like regular stored fields by the auto-save system,
   * or edits to them would be silently dropped.
   *
   * There is no reliable way to infer these: computed fields are read-only by
   * default per DataDefinition::isReadOnly() and `moderation_state` declares
   * setReadOnly(FALSE), but core's `path` field does not, so both are
   * hardcoded.
   *
   * @todo Stop hardcoding these in https://drupal.org/i/3503446
   */
  public static function isPersistedComputedField(FieldDefinitionInterface $field_definition): bool {
    return \is_a($field_definition->getClass(), PathFieldItemList::class, TRUE)
      || \is_a($field_definition->getClass(), ModerationStateFieldItemList::class, TRUE);
  }

  /**
   * Converts an entity to array ready for storing in auto-save item.
   *
   * For content entities, exclude computed fields: only actually stored data
   * matters here — except computed fields that are persisted on save.
   *
   * For config entities that carry a component tree, the tree's inputs are
   * optimized so the stored form is canonical (two equivalent trees produce
   * identical output). Content entities receive the equivalent treatment via
   * TypedDataHelper::castRawPhpTypes(), which optimizes ComponentTreeItem
   * instances as it walks the fields.
   *
   * @see self::isPersistedComputedField()
   * @see \Drupal\canvas\Utility\TypedDataHelper::castRawPhpTypes()
   */
  private static function toStorableArray(EntityInterface $entity): array {
    if ($entity instanceof FieldableEntityInterface) {
      $values = [];
      foreach ($entity->getFields(include_computed: TRUE) as $name => $field_item_list) {
        \assert($field_item_list instanceof FieldItemListInterface);
        if ($field_item_list->getFieldDefinition()->isComputed() && !self::isPersistedComputedField($field_item_list->getFieldDefinition())) {
          continue;
        }
        $values[$name] = TypedDataHelper::castRawPhpTypes($field_item_list);
      }
      return $values;
    }

    if ($entity instanceof ComponentTreeEntityInterface) {
      // Optimize the component inputs so the stored form is canonical. Operate
      // on a clone: ::toArray() below reads the mutated tree, but the entity
      // passed in by the caller must not be modified as a side effect.
      $entity = clone $entity;
      $tree = $entity->getComponentTree();
      foreach ($tree as $component) {
        \assert($component instanceof ComponentTreeItem);
        $component->optimizeInputs();
      }
      $entity->setComponentTree($tree->getValue());
    }

    return $entity->toArray();
  }

  public function saveEntity(EntityInterface $entity, ?string $clientId = NULL): void {
    $key = $this->getAutoSaveKey($entity);
    $data = self::normalizeEntity($entity);
    $data_hash = self::generateHash($data);
    $original_hash = $this->getUnchangedHash($entity);

    $has_form_violations = FALSE;
    if ($entity instanceof FieldableEntityInterface) {
      $has_form_violations = $this->getEntityFormViolations($entity)->count() > 0;
    }
    // 💡 If you are debugging why an entry is being created, but you didn't
    // expect one to be, the code below can be evaluated in a debugger and will
    // show you which field varies.
    // @code
    // $original = self::normalizeEntity($this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id()))
    // $data_hash = \array_map(self::generateHash(...), $data)
    // $original_hash = \array_map(self::generateHash(...), $original)
    // \array_diff($data_hash, $original_hash)
    // \array_diff($original_hash, $data_hash)
    // @endcode
    if ($original_hash !== NULL && \hash_equals($original_hash, $data_hash) && !$has_form_violations) {
      // We've reset back to the original values. Clear the auto-save entry but
      // keep the hash.
      $this->delete($entity);
      return;
    }

    // Avoid overwriting the original hash; it would break conflict detection.
    $existing_entry = $this->autoSaveStore->get($key);
    if (\is_array($existing_entry) && \array_key_exists(self::AUTO_SAVE_STORED_ENTITY_HASH_KEY, $existing_entry)) {
      $original_hash = $existing_entry[self::AUTO_SAVE_STORED_ENTITY_HASH_KEY];
    }

    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => self::toStorableArray($entity),
      'langcode' => $entity->language()->getId(),
      // 'langcode' alone is not enough to determine whether this is the default
      // translation: an entity's default translation langcode is entity-
      // specific and can only be known by loading the entity from storage.
      // Storing this boolean at write time (when the real entity is available)
      // avoids a loadUnchanged() call at read time.
      'is_default_translation' => !($entity instanceof TranslatableInterface) || $entity->isDefaultTranslation(),
      'label' => (string) $entity->label(),
      self::AUTO_SAVE_STORED_ENTITY_HASH_KEY => $original_hash,
      'data_hash' => $data_hash,
      'client_id' => $clientId,
      'owner' => (int) $this->currentUser->id(),
      'updated' => $this->time->getRequestTime(),
    ];
    \assert(!\is_null($auto_save_data['entity_id']));

    $this->autoSaveStore->set($key, $auto_save_data);
    $this->cache->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * @todo Remove this in https://drupal.org/i/3505018 and
   *   https://drupal.org/i/3500795.
   */
  public function saveEntityFormViolations(FieldableEntityInterface $entity, ?ConstraintViolationListInterface $violations = NULL): self {
    $key = self::getAutoSaveKey($entity);
    if ($violations === NULL) {
      $this->formViolationsStore->delete($key);
      return $this;
    }
    $this->formViolationsStore->set($key, $violations);
    $this->cache->delete($key);
    return $this;
  }

  /**
   * @todo Remove this in https://drupal.org/i/3505018 and
   *   https://drupal.org/i/3500795.
   */
  public function getEntityFormViolations(FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return $this->formViolationsStore->get(self::getAutoSaveKey($entity)) ?? new ConstraintViolationList();
  }

  /**
   * Saves a component instance form violation.
   *
   * Some component source plugins need to submit Drupal forms to determine
   * validation errors. This happens during conversion of the client model to
   * input values, which is separate to validation. In order to store a record
   * of any form violations, component source plugins can make use of this
   * method.
   *
   * @see \Drupal\canvas\ComponentSource\ComponentSourceInterface::clientModelToInput
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent::clientModelToInput
   * @see \Drupal\canvas\Form\ComponentInstanceForm
   *
   * @todo Remove this in https://drupal.org/i/3505018 and
   *    https://drupal.org/i/3500795.
   */
  public function saveComponentInstanceFormViolations(string $component_uuid, ?ConstraintViolationListInterface $violations = NULL): self {
    if ($violations === NULL) {
      $this->componentInstanceFormViolationsStore->delete($component_uuid);
      return $this;
    }
    $this->componentInstanceFormViolationsStore->set($component_uuid, $violations);
    return $this;
  }

  /**
   * @todo Remove this in https://drupal.org/i/3505018 and
   *    https://drupal.org/i/3500795.
   */
  public function getComponentInstanceFormViolations(string $component_uuid): ConstraintViolationListInterface {
    return $this->componentInstanceFormViolationsStore->get($component_uuid) ?? new ConstraintViolationList();
  }

  private static function normalizeEntity(EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return self::toStorableArray($entity);
    }
    $normalized = [];
    $fields = $entity->getFields();
    if ($entity instanceof EntityChangedInterface) {
      // If the entity has a 'changed' field, we don't want to include it in the
      // normalized data, as will be updated when we create an entity to
      // compare against the save version.
      // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveEntity().
      $fields = \array_filter($fields, static fn (FieldItemListInterface $field) => $field->getFieldDefinition()->getType() !== 'changed');
      // Similarly, we don't want to include the 'externalUpdates' field as it's
      // not truly an entity field, but something used to track programmatic
      // updates to the entity data in Redux.
      // @todo We can probably remove this when we refactor the pageData slice
      //   in https://drupal.org/i/3535569.
      $fields = \array_filter($fields, static fn (FieldItemListInterface $field) => $field->getFieldDefinition()->getType() !== 'externalUpdates');
    }
    // Exclude all computed properties except those that are user-editable and
    // persisted elsewhere on save (path aliases, moderation states).
    $fields = \array_filter($fields, static fn (FieldItemListInterface $field) => !$field->getFieldDefinition()->isComputed() || self::isPersistedComputedField($field->getFieldDefinition()));

    foreach (\array_keys($fields) as $name) {
      $items = $entity->get($name);
      // Exclude items that are empty.
      if ($items->isEmpty()) {
        continue;
      }
      $item_list = \iterator_to_array($items);
      // The computed path alias field always yields an item carrying the
      // entity's langcode, even when no alias is set. Such an item carries no
      // user-editable data, so treat it as empty to avoid spurious auto-saves.
      // (Drupal 11.4 stopped round-tripping the alias langcode through the
      // entity form, which otherwise kept this symmetric.)
      if ($items->getFieldDefinition()->getType() === 'path') {
        $item_list = \array_filter($item_list, static fn (FieldItemInterface $item): bool => (string) $item->get('alias')->getValue() !== '');
        if ($item_list === []) {
          continue;
        }
      }
      $normalized[$name] = TypedDataHelper::castRawPhpTypes($items);
    }
    return $normalized;
  }

  public static function getAutoSaveKey(EntityInterface $entity): string {
    // @todo Make use of https://www.drupal.org/project/drupal/issues/3026957
    // @todo This will likely to also take into account the workspace ID.
    if ($entity instanceof TranslatableInterface) {
      return $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $entity->language()->getId();
    }
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @return array{autoSaveStartingPoint: int|string|null, hash: string|null}
   */
  public function getClientAutoSaveData(EntityInterface $entity): array {
    $autoSaveEntity = $this->getAutoSaveEntity($entity);

    // We need to load the stored entity to be able to construct the auto-save
    // starting point.
    \assert($entity->id() !== NULL);
    $savedEntity = $this->entityTypeManager->getStorage($entity->getEntityTypeId())
      ->loadUnchanged($entity->id());
    \assert($savedEntity instanceof EntityInterface);

    // If available we must use the revision ID and the changed time because
    // not all entity types will increment the revision ID on every change.
    $autoSaveStartRevision = $savedEntity instanceof RevisionableInterface
      ? $savedEntity->getRevisionId()
      : \hash('xxh64', \json_encode($savedEntity->toArray(), JSON_THROW_ON_ERROR));
    if ($savedEntity instanceof EntityChangedInterface) {
      $autoSaveStartRevision .= '-' . $savedEntity->getChangedTime();
    }
    return [
      'autoSaveStartingPoint' => $autoSaveStartRevision,
      'hash' => $autoSaveEntity->hash,
    ];
  }

  private function getUnchangedHash(EntityInterface $entity): ?string {
    \assert(!\is_null($entity->id()));
    $original = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($original === NULL) {
      return NULL;
    }
    return self::generateHash(self::normalizeEntity($original));
  }

  public function getAutoSaveEntity(EntityInterface $entity, bool $bypass_cache = FALSE): AutoSaveEntity {
    $key = $this->getAutoSaveKey($entity);
    if (!$bypass_cache) {
      $cached = $this->cache->get($key);
      if ($cached) {
        \assert($cached->data instanceof AutoSaveEntity);
        return $cached->data;
      }
    }
    $auto_save_data = $this->autoSaveStore->get($key);
    if (\is_null($auto_save_data)) {
      return AutoSaveEntity::empty();
    }

    /** @var AutoSaveEntry $auto_save_data */
    \assert(\is_array($auto_save_data));
    \assert(\array_key_exists('data', $auto_save_data));
    \assert(\array_key_exists('entity_type', $auto_save_data));
    \assert(\is_array($auto_save_data['data']));
    $entity = $this->createEntityFromAutoSaveEntry($auto_save_data);
    $auto_save_entity = new AutoSaveEntity(
      entity: $entity,
      hash: $auto_save_data['data_hash'],
      clientId: $auto_save_data['client_id'],
      // Used to populate "Updated" element in the side-by-side comparison UI.
      // @see \Drupal\canvas\ControllerApiLayoutController::get()
      // @todo Revisit as part of https://www.drupal.org/project/canvas/issues/3591544
      updated: $auto_save_data['updated']
    );
    // Memoize the reconstructed entity to avoid calling ::create() repeatedly
    // during layout preview rendering. $this->cache MUST be a non-serializing
    // backend; see the $cache constructor parameter for why.
    $this->cache->set($key, $auto_save_entity, tags: [self::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * @param AutoSaveEntry $entry
   *
   * @todo Consider calling \Drupal\Core\Entity\EntityChangedInterface::setChangedTime() with the auto-save entry's 'updated' value in https://www.drupal.org/project/canvas/issues/3591544
   */
  private function createEntityFromAutoSaveEntry(array $entry): EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entry['entity_type']);
    $entity = $storage->create($entry['data']);
    // Auto-saves can only exist for entities that have already been saved, so
    // the reconstructed entity is never new.
    $entity->enforceIsNew(FALSE);
    // Record the loaded revision ID. ::create() does not set it, but some form
    // widgets load a specific revision and fail without it.
    // @see \Drupal\content_moderation\Plugin\Field\FieldWidget\ModerationStateWidget::formElement()
    if ($entity instanceof RevisionableInterface) {
      $entity->updateLoadedRevisionId();
    }

    // A content entity's auto-save snapshot only stores the single translation
    // that was edited, so the entity created from it knows about just that one
    // translation — and, for a non-default translation, ::create() even treats
    // it as the default. Overlay the snapshot onto a fresh copy of the stored
    // entity so the reconstructed entity is aware of every translation that
    // exists and keeps the real default translation. Config entities have no
    // content translations, an entity deleted since the auto-save was written
    // has nothing to overlay onto, and a snapshot for a translation that does
    // not (yet) exist in storage cannot be merged onto it: all keep the
    // snapshot-only reconstruction.
    // Clone the loaded entity before overlaying: loadUnchanged() repopulates
    // the static cache, so mutating its return value (via $target below) would
    // leak the snapshot's values into the stored entity that later loads —
    // including loadMultiple() — hand back.
    $loaded = $entity instanceof ContentEntityInterface ? $storage->loadUnchanged($entry['entity_id']) : NULL;
    $stored = $loaded instanceof ContentEntityInterface ? clone $loaded : NULL;
    $langcode = $entity->language()->getId();
    if (!$stored instanceof ContentEntityInterface || !$stored->hasTranslation($langcode)) {
      // @todo Also check \Drupal\content_translation\ContentTranslationManager::isEnabled() for content entities in https://git.drupalcode.org/project/canvas/-/work_items/3571130
      if ($entity instanceof ContentEntityBase && $entity->isTranslatable()) {
        // Old entries predate 'is_default_translation' — they can only have
        // been created from the default translation, so TRUE is the correct
        // fallback.
        // @todo Remove this fallback in Canvas 2.0.
        $entity->setDefaultTranslationEnforced($entry['is_default_translation'] ?? TRUE);
      }
      return $entity;
    }

    $stored_langcodes = \array_keys($stored->getTranslationLanguages());
    $target = self::overlaySnapshotOntoStored($entity, $stored, $langcode);
    $stored->enforceIsNew(FALSE);
    // Overlaying the snapshot must not change which translations exist.
    \assert(\array_keys($target->getTranslationLanguages()) === $stored_langcodes);
    return $target;
  }

  /**
   * Overlays a single-translation snapshot's fields onto a stored translation.
   *
   * Copies every stored (and persisted-computed) field value from the snapshot
   * onto the matching translation of $stored, leaving the entity keys and
   * revision metadata — which identify the stored entity — untouched.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $snapshot
   *   The entity reconstructed from a single translation's auto-save snapshot.
   * @param \Drupal\Core\Entity\ContentEntityInterface $stored
   *   A freshly loaded copy of the stored entity, holding every translation.
   * @param string $langcode
   *   The translation the snapshot belongs to; must exist on $stored.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The overlaid translation of $stored.
   */
  private static function overlaySnapshotOntoStored(ContentEntityInterface $snapshot, ContentEntityInterface $stored, string $langcode): ContentEntityInterface {
    $entity_definition = $stored->getEntityType();
    \assert($entity_definition instanceof ContentEntityTypeInterface);
    // Entity keys and revision metadata identify the stored entity and must not
    // be overwritten by the snapshot's copy of them.
    $skip_keys = \array_filter([
      $entity_definition->getKey('id'),
      $entity_definition->getKey('revision'),
      $entity_definition->getKey('uuid'),
      $entity_definition->getKey('langcode'),
      $entity_definition->getRevisionMetadataKey('revision_created'),
      $entity_definition->getRevisionMetadataKey('revision_user'),
    ], \is_string(...));
    $target = $stored->getTranslation($langcode);
    foreach ($snapshot->getFields() as $field_name => $field) {
      if (\in_array($field_name, $skip_keys, TRUE)) {
        continue;
      }
      $field_definition = $field->getFieldDefinition();
      // Only stored data matters here, except computed fields that are
      // persisted on save (e.g. path, moderation_state).
      // @see self::isPersistedComputedField()
      if ($field_definition->isComputed() && !self::isPersistedComputedField($field_definition)) {
        continue;
      }
      $target->set($field_name, $field->getValue());
    }
    return $target;
  }

  /**
   * Reconstructs a content entity's draft with every translation overlaid.
   *
   * ::getAutoSaveEntity() overlays only the requested translation's snapshot,
   * so a translation with no snapshot of its own is returned at its stored
   * value — even when a *sibling* translation has a pending draft. Previewing
   * such a translation then reconciles and re-saves every translation (the
   * symmetric component-tree columns must stay in sync), which would overwrite
   * the sibling's draft with the stored value.
   *
   * This overlays every pending translation snapshot of the same entity onto a
   * single stored copy, returning the requested translation as the active one,
   * so the preview — and the reconciliation it triggers — sees and preserves
   * every translation's draft. It is intentionally separate from
   * ::getAutoSaveEntity(), whose per-translation emptiness contract (a
   * translation is "empty" unless it has its own snapshot) is relied on by
   * per-translation callers such as form building and "unsaved changes"
   * indicators.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, in the translation to preview.
   *
   * @return \Drupal\canvas\AutoSaveEntity
   *   The full multi-translation draft, or empty when no translation of the
   *   entity has a pending auto-save.
   */
  public function getAutoSaveEntityForPreview(ContentEntityInterface $entity): AutoSaveEntity {
    // An unsaved entity cannot have an auto-save.
    if ($entity->id() === NULL) {
      return AutoSaveEntity::empty();
    }
    $requested_key = $this->getAutoSaveKey($entity);
    $entity_type_id = $entity->getEntityTypeId();
    $entity_id = (string) $entity->id();
    // Every pending translation auto-save of this content entity.
    $group = \array_filter(
      $this->getAllAutoSaveList(with_entities: FALSE, with_conflicts: FALSE),
      static fn (array $entry): bool => $entry['entity_type'] === $entity_type_id
        && (string) $entry['entity_id'] === $entity_id,
    );
    if ($group === []) {
      return AutoSaveEntity::empty();
    }
    // Carry the requested translation's hash/client when it has its own draft;
    // otherwise use a sibling's so conflict detection still has a basis.
    $representative = $group[$requested_key] ?? \reset($group);
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $loaded = $storage->loadUnchanged($entity->id());
    $requested_langcode = $entity->language()->getId();
    // When the stored entity is gone, or the requested translation does not yet
    // exist in storage (a draft for a not-yet-saved translation), there is no
    // stored translation to overlay siblings onto. Reconstruct the requested
    // translation's own snapshot in isolation, matching single-translation
    // behavior; ::createEntityFromAutoSaveEntry() handles both edges.
    if (!$loaded instanceof ContentEntityInterface || !$loaded->hasTranslation($requested_langcode)) {
      if (!isset($group[$requested_key])) {
        return AutoSaveEntity::empty();
      }
      $reconstructed = $this->createEntityFromAutoSaveEntry($group[$requested_key]);
      return new AutoSaveEntity($reconstructed, $representative['data_hash'], $representative['client_id'], $representative['updated']);
    }
    // Clone before overlaying: loadUnchanged() repopulates the static cache, so
    // mutating its return value would leak the draft into later loads.
    $stored = clone $loaded;
    $stored->enforceIsNew(FALSE);
    foreach ($group as $entry) {
      \assert(\is_array($entry['data']));
      $snapshot = $storage->create($entry['data']);
      \assert($snapshot instanceof ContentEntityInterface);
      $langcode = $snapshot->language()->getId();
      // A snapshot for a translation that does not exist in storage cannot be
      // overlaid onto it (the isolated-reconstruction branch above covers that
      // snapshot when it is the requested one).
      if (!$stored->hasTranslation($langcode)) {
        continue;
      }
      self::overlaySnapshotOntoStored($snapshot, $stored, $langcode);
    }
    return new AutoSaveEntity($stored->getTranslation($requested_langcode), $representative['data_hash'], $representative['client_id'], $representative['updated']);
  }

  /**
   * Gets all auto-save data.
   *
   * @param bool $with_entities
   *   Whether the auto-save items should contain entity instances in 'entity'.
   * @param bool $with_conflicts
   *   Whether the auto-save items contain 'conflict_id' for items with detected
   *   unresolved conflicts due to external updates to the entity on which the
   *   auto-save item was based.
   *
   * @return array<string, AutoSaveEntry>
   *   All auto-save data entries.
   */
  public function getAllAutoSaveList(bool $with_entities, bool $with_conflicts): array {
    /** @var array<string, AutoSaveEntry> $entries */
    $entries = $this->autoSaveStore->getAll();

    // StagedLanguageConfigOverride entries are internal implementation details:
    // they are published implicitly when their base config entity is published
    // (see ApiAutoSaveController::post()), and discarded atomically via
    // getTranslationGroupAutoSaves().
    // Furthermore, no sensible behavior is possible when $with_entities == TRUE
    // because unlike for content entity translations, they cannot be loaded and
    // set as the active translation for the config entity they target.
    // @see https://www.drupal.org/project/drupal/issues/3203918
    // Hence it is safer to make it seem as if they do not exist, at this lower
    // level — unlike how content entity translation filtering was implemented
    // in https://git.drupalcode.org/project/canvas/-/work_items/3591704.
    // @todo Remove this filtering in https://git.drupalcode.org/project/canvas/-/work_items/3591703.
    $entries = \array_filter(
      $entries,
      static fn (array $entry): bool => ($entry['entity_type'] ?? NULL) !== StagedLanguageConfigOverride::ENTITY_TYPE_ID,
    );

    // Sort by key to ensure consistent ordering.
    \ksort($entries);
    /** @var array<string, AutoSaveEntry> $result */
    $result = \array_map(fn (array $entry) => $entry +
    // Append the owner and updated data into each entry, and an entity object
    // upon request.
    [
      // Remove the unique session key for anonymous users.
      'owner' => \is_numeric($entry['owner']) ? (int) $entry['owner'] : 0,
      'entity' => $with_entities ? $this->createEntityFromAutoSaveEntry($entry) : NULL,
    ], $entries);

    if ($with_conflicts) {
      // Add conflict_id property only for Page entities with unresolved
      // conflicts.
      // @todo Expand to other entities in https://www.drupal.org/project/canvas/issues/3591544
      $result = \array_map(
        function (array $entry): array {
          $unresolved_conflict = $this->getUnresolvedConflict($entry);
          unset($entry[self::AUTO_SAVE_CONFLICT_KEY]);
          if ($entry['entity_type'] === Page::ENTITY_TYPE_ID && $unresolved_conflict) {
            $entry[self::AUTO_SAVE_CONFLICT_KEY] = $unresolved_conflict;
          }
          return $entry;
        },
        $result,
      );
    }
    else {
      $result = \array_map(fn (array $entry) =>
        \array_diff_key($entry, \array_flip([self::AUTO_SAVE_CONFLICT_KEY])),
        $result
      );
    }

    /** @var array<string, AutoSaveEntry> $result */
    return $result;
  }

  /**
   * Groups content entity auto-save entries by entity, one per translation.
   *
   * A single content entity may have multiple auto-save entries when several
   * translations were edited independently. Each entry holds a snapshot for one
   * translation. This method collects all snapshots for the same entity into a
   * group so they can be passed to
   * ApiAutoSaveController::applyAutoSaveTranslationSnapshots(), which applies
   * them all onto one loaded copy in a single save.
   *
   * Content entity coalescing lives here rather than in getAutoSaveEntity() /
   * getAllAutoSaveList() because it requires loadUnchanged() to merge
   * field-level changes onto the stored entity — a publish-specific operation
   * that must not run at reconstruction time. This method therefore only groups
   * the raw snapshots; the actual merge happens in the controller at publish
   * time.
   * Contrast with config entities, where coalescing is non-destructive and
   * happens at load time in ComponentTreeConfigEntityBase::getTranslation().
   *
   * @param array<string, AutoSaveEntry> $auto_saves
   *   A subset of the getAllAutoSaveList() result, already filtered to the
   *   entries that should be published, with 'entity' populated.
   *
   * @return array<string, \Drupal\Core\Entity\ContentEntityInterface[]>
   *   Snapshot entities grouped by "{entity_type}:{entity_id}", preserving the
   *   order of the input. Config entity entries are silently skipped.
   */
  public static function groupContentEntityAutoSaves(array $auto_saves): array {
    $groups = [];
    foreach ($auto_saves as $entry) {
      $entity = $entry['entity'];
      if (!$entity instanceof ContentEntityInterface) {
        continue;
      }
      $group_key = $entity->getEntityTypeId() . ':' . $entity->id();
      $groups[$group_key][] = $entity;
    }
    return $groups;
  }

  /**
   * Checks if there is an unresolved conflict and returns its id.
   *
   * This method only handles conflicts in scenarios where an entity on which
   * the auto-save entry was based on is updated externally, e.g. using config
   * syncing, entity saves performed by Drush scripts, or even just using an
   * entity's canonical edit form.
   *
   * Such conflicts are detected by storing the 'original_hash' of the entity
   * during the ::saveEntity() and comparing it to the hash of the current
   * version in the entity storage.
   *
   * When a user resolves a conflict without discarding the auto-save item,
   * ::resolveConflict() advances 'original_hash' to the current stored entity
   * hash so that subsequent calls find no mismatch.
   *
   * @param AutoSaveEntry $entry
   *
   * @return string|null
   */
  private function getUnresolvedConflict(array $entry): string|null {
    // For now, only Page entities supported.
    // @todo Expand in https://www.drupal.org/project/canvas/issues/3591544
    if ($entry['entity_type'] !== Page::ENTITY_TYPE_ID) {
      return NULL;
    }

    $entity = $this->entityTypeManager->getStorage($entry['entity_type'])->loadUnchanged($entry['entity_id']);
    \assert(!\is_null($entity));

    // Compare the original_hash in auto-save entry vs latest entity hash.
    if ($entry[self::AUTO_SAVE_STORED_ENTITY_HASH_KEY] === self::generateHash(self::normalizeEntity($entity))) {
      // Hashes are matching - no conflict.
      return NULL;
    }

    // Hashes are not matching - conflict detected.
    return self::getConflictId($entity);
  }

  /**
   * @todo Refactor to support config entities in https://www.drupal.org/project/canvas/issues/3591544
   *
   * @return ConflictId
   */
  public static function getConflictId(EntityInterface $entity): string {
    return $entity instanceof Page ? (string) $entity->getLoadedRevisionId() : '';
  }

  /**
   * Checks if an entity has an auto-save with an unresolved conflict.
   *
   * @todo Refactor to support config entities in https://www.drupal.org/project/canvas/issues/3591544
   *
   * @return ConflictId|null
   */
  public function getUnresolvedConflictForEntity(Page $entity): string|NULL {
    $key = $this->getAutoSaveKey($entity);
    $auto_save_data = $this->autoSaveStore->get($key);
    // No auto-save, no conflict.
    if (\is_null($auto_save_data)) {
      return NULL;
    }

    return $this->getUnresolvedConflict($auto_save_data);
  }

  /**
   * Attempts to resolve an auto-save entry's active conflict.
   *
   * Reads the auto-save entry, checks the requested conflict against the active
   * one, and persists the resolution in a single pass. The returned outcome
   * distinguishes every failure reason so callers can map them to distinct
   * responses.
   *
   * On success, 'original_hash' is advanced to the current stored entity hash
   * so that subsequent ::getUnresolvedConflict() calls find no mismatch.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity whose auto-save conflict to resolve.
   * @param string $resolved_conflict_id
   *   The conflict id the client believes is active.
   *
   * @return \Drupal\canvas\Controller\ConflictResolutionOutcomeEnum
   *   The outcome of the resolution attempt.
   */
  public function resolveConflict(EntityInterface $entity, string $resolved_conflict_id): ConflictResolutionOutcomeEnum {
    $key = $this->getAutoSaveKey($entity);
    $auto_save_data = $this->autoSaveStore->get($key);

    // No auto-save entry, so there is nothing to resolve.
    if (\is_null($auto_save_data)) {
      return ConflictResolutionOutcomeEnum::NoAutoSaveItem;
    }

    \assert(!$entity->isNew());
    $stored = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    \assert(!\is_null($stored));
    $current_hash = self::generateHash(self::normalizeEntity($stored));
    /** @var AutoSaveEntry $auto_save_data */

    if ($auto_save_data[self::AUTO_SAVE_STORED_ENTITY_HASH_KEY] === $current_hash) {
      // Hashes match — no conflict to resolve.
      return ConflictResolutionOutcomeEnum::NoActiveConflict;
    }

    $active_conflict = self::getConflictId($stored);

    // The active conflict differs from the one the request tried to resolve;
    // a newer external change has appeared.
    if ($active_conflict !== $resolved_conflict_id) {
      return ConflictResolutionOutcomeEnum::ConflictMismatch;
    }
    // Advance stored entity hash.
    $this->setStoredEntityHash($key, $current_hash, $auto_save_data);
    $this->cache->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);

    return ConflictResolutionOutcomeEnum::Resolved;
  }

  /**
   * @see ::onCanvasConfigEntitySave()
   */
  public function delete(EntityInterface $entity): void {
    $auto_save_entity = $this->getAutoSaveEntity($entity);
    if (!$auto_save_entity->isEmpty() && $auto_save_entity->entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
      BrandKit::clearAutoSaveFileUsage($auto_save_entity->entity, (string) $entity->id());
    }
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $key = $this->getAutoSaveKey($entity);
    $this->autoSaveStore->delete($key);
    $this->formViolationsStore->delete($key);
    // A discarded auto-save entry must not leave an orphan health result.
    $this->healthRecords->deleteForEntity($entity, HealthCheck::AutoSave);
    if ($entity instanceof ContentEntityInterface) {
      $canvas_fields = \array_keys(
        \array_filter(
          $entity->getFields(),
          static fn(FieldItemListInterface $field
          ): bool => $field->getItemDefinition()->getClass(
            ) === ComponentTreeItem::class
        )
      );
      $component_uuids = \array_reduce($canvas_fields, static fn (array $carry, string $field_name): array => [
        ...$carry,
        ...\array_column($entity->get($field_name)->getValue(), 'uuid'),
      ], []);
      $this->componentInstanceFormViolationsStore->deleteMultiple(\array_unique($component_uuids));
    }
  }

  /**
   * Returns the staged config-translation drafts owned by a config entity.
   *
   * A ComponentTreeConfigEntityBase and its per-language
   * StagedLanguageConfigOverride drafts are separate auto-save entries that
   * must be discarded (and, eventually, published) together: each override's
   * entity ID is "{langcode}.{config_name}", so they are matched by config
   * name. This is the config-translation sibling of
   * ::groupContentEntityAutoSaves(), which groups a content entity's
   * per-translation snapshots.
   *
   * @param \Drupal\canvas\Entity\ComponentTreeConfigEntityBase $entity
   *   The config entity whose staged translation drafts to collect.
   *
   * @return \Drupal\canvas\Entity\StagedLanguageConfigOverride[]
   *   The staged override drafts currently in auto-save for $entity.
   */
  public function groupConfigEntityAutoSaves(ComponentTreeConfigEntityBase $entity): array {
    $suffix = '.' . $entity->getConfigDependencyName();
    /** @var array<string, AutoSaveEntry> $entries */
    $entries = $this->autoSaveStore->getAll();
    $matches = \array_filter(
      $entries,
      static fn (array $entry): bool =>
        ($entry['entity_type'] ?? NULL) === StagedLanguageConfigOverride::ENTITY_TYPE_ID
        && \is_string($entry['entity_id'] ?? NULL)
        && \str_ends_with((string) $entry['entity_id'], $suffix),
    );
    return \array_values(\array_map(function (array $entry): StagedLanguageConfigOverride {
      $override = $this->createEntityFromAutoSaveEntry($entry);
      \assert($override instanceof StagedLanguageConfigOverride);
      return $override;
    }, $matches));
  }

  /**
   * Returns every auto-save in an entity's atomic pending-changes set.
   *
   * A single editor action drafts changes that must be published or discarded
   * as one unit, even though they can span multiple auto-save entries:
   * - For a content entity, symmetric translation writes the shared
   *   component-tree columns (e.g. component_version) across every translation
   *   at once, so each edited translation is a separate per-langcode auto-save
   *   entry of the same entity. Leaving a sibling behind keeps a stale, and
   *   possibly invalid, draft pending.
   * - For a ComponentTreeConfigEntityBase, the base draft and each per-language
   *   StagedLanguageConfigOverride draft are separate entities (distinct entity
   *   types and IDs) that nothing in core links back together.
   *
   * Given any member of the set, this returns the whole set, so callers (the
   * discard endpoint) can act on it atomically.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Any member of the pending-changes set: a content entity (any
   *   translation), a config entity, or one of its staged language config
   *   overrides.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The auto-save entities forming $entity's pending-changes set, empty when
   *   it has no auto-save at all.
   */
  public function getTranslationGroupAutoSaves(EntityInterface $entity): array {
    // Config: the base draft plus each per-language override draft. These are
    // distinct entity types and IDs, matched by config name, so the generic
    // type+id filter below cannot find them.
    $base = match (TRUE) {
      $entity instanceof ComponentTreeConfigEntityBase => $entity,
      $entity instanceof StagedLanguageConfigOverride => $this->configManager->loadConfigEntityByName($entity->getName()),
      default => NULL,
    };
    if ($base instanceof ComponentTreeConfigEntityBase) {
      $base_draft = $this->getAutoSaveEntity($base)->isEmpty() ? [] : [$base];
      return [...$base_draft, ...$this->groupConfigEntityAutoSaves($base)];
    }

    // Content (and any other entity type): every auto-save entry sharing this
    // entity's type and ID — i.e. all edited translations of the same entity.
    // @todo This groups every pending translation together, which is correct
    //   for symmetric translation. Make it selective once asymmetric
    //   translation is supported: https://www.drupal.org/i/3522198
    $entries = \array_filter(
      $this->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE),
      static fn (array $entry): bool => $entry['entity_type'] === $entity->getEntityTypeId()
        && (string) $entry['entity_id'] === (string) $entity->id(),
    );
    return \array_values(\array_map(
      static function (array $entry): EntityInterface {
        \assert($entry['entity'] instanceof EntityInterface);
        return $entry['entity'];
      },
      $entries,
    ));
  }

  public function deleteAll(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->autoSaveStore->deleteAll();
    $this->formViolationsStore->deleteAll();
    $this->componentInstanceFormViolationsStore->deleteAll();
    $this->healthRecords->clear(HealthCheck::AutoSave);
  }

  private static function generateHash(array $data): string {
    // When called from ::recordInitialClientSideRepresentation() and ::save()
    // the keys for an individual component are in different orders. This causes
    // the hash to be different though the data is functionally the same.
    SortArray::sortByKeyRecursive($data);
    // We use \json_encode here instead of \serialize because we're not dealing
    // with PHP Objects and this ensures the representation hashed from PHP is
    // consistent with the representation transmitted by the client. Some of the
    // UTF characters we use in expressions are represented differently in JSON
    // encoding and hence using \serialize would yield two different hashes
    // depending on whether the hashing occurred before/after transfer from the
    // client.
    return \hash('xxh64', \json_encode($data, JSON_THROW_ON_ERROR));
  }

  public function onCanvasConfigEntitySave(ConfigCrudEvent $event): void {
    [$module] = explode('.', $event->getConfig()->getName(), 2);
    if ($module !== 'canvas') {
      return;
    }

    $entity = $this->configManager->loadConfigEntityByName($event->getConfig()->getName());
    if (!$entity) {
      return;
    }
    // Auto-saves can only occur for Canvas config entities modified by the
    // Canvas UI.
    if (!$entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
      return;
    }

    $autoSaveData = $this->getAutoSaveEntity($entity);
    if ($autoSaveData->isEmpty()) {
      return;
    }
    $autoSaveEntity = $autoSaveData->entity;
    \assert($autoSaveEntity instanceof CanvasHttpApiEligibleConfigEntityInterface);

    // Update the properties of the config entity that can be changed without
    // invalidating the draft (`label`, `status`, and the exceptions below), if
    // they've changed.
    // @todo Consider auto-updating the auto-save entries for other config entity properties, but that will need very careful evaluation.
    $auto_save_update_needed = FALSE;
    \assert($entity->getEntityType() instanceof ConfigEntityTypeInterface);
    $properties_to_assess = $entity->getEntityType()->getPropertiesToExport();
    \assert(\is_array($properties_to_assess));
    $auto_save_updatable_properties = \array_intersect_key(
      $entity->getEntityType()->getKeys(),
      \array_flip(['status', 'label']),
    );
    // Calculated dependencies are recalculated from the other exported
    // properties on every save, so they can never on their own be a reason to
    // discard a draft: whatever they were derived from is assessed below
    // anyway. (Enforced dependencies are authored rather than derived, but they
    // are not worth losing an editor's work over either.)
    // @see \Drupal\Core\Config\Entity\ConfigEntityBase::preSave()
    $auto_save_updatable_properties['dependencies'] = 'dependencies';
    // A content template's page variant selection is edited on its own, through
    // the config API rather than the layout auto-save flow, so changing it must
    // update the pending draft instead of discarding the editor's unpublished
    // component changes. It is a plain exported property rather than an entity
    // key, hence it cannot come from ::getKeys() above.
    // @see \Drupal\canvas\Entity\ContentTemplate::updateFromClientSide()
    if ($entity instanceof ContentTemplate) {
      $auto_save_updatable_properties['page_variant'] = 'page_variant';
    }

    // Ensure that no other properties were modified; otherwise the auto-save
    // entry must be deleted.
    $auto_save_not_updatable_properties = \array_diff_key($properties_to_assess, array_flip($auto_save_updatable_properties));
    foreach ($auto_save_not_updatable_properties as $property) {
      if ($event->isChanged($property)) {
        $this->delete($entity);
        return;
      }
    }

    foreach ($auto_save_updatable_properties as $auto_save_updatable_property) {
      if ($event->isChanged($auto_save_updatable_property)) {
        $autoSaveEntity->set($auto_save_updatable_property, $entity->get($auto_save_updatable_property));
        $auto_save_update_needed = TRUE;
      }
    }

    // Finally: the goal: to update rather than delete the auto-save entry when
    // safe.
    if ($auto_save_update_needed) {
      // Advance `original_hash` before `saveEntity()` so the preservation
      // guard in `saveEntity()` picks up the already-advanced hash in a
      // single write.
      $this->setStoredEntityHash(
        self::getAutoSaveKey($autoSaveEntity),
        // Hash the freshly-saved stored entity, not the auto-save draft.
        self::generateHash(self::normalizeEntity($entity)),
      );
      $this->saveEntity($autoSaveEntity, $autoSaveData->clientId);
    }
  }

  public function onCanvasConfigDelete(ConfigCrudEvent $event): void {
    $autoSaveEntities = $this->getAllAutoSaveList(with_entities: TRUE, with_conflicts: FALSE);
    $autoSaveEntities = array_filter($autoSaveEntities, fn($entityData) => $entityData['entity'] instanceof StagedConfigUpdate);
    foreach ($autoSaveEntities as $autoSaveEntity) {
      $staged_config_update = $autoSaveEntity['entity'];
      \assert($staged_config_update instanceof StagedConfigUpdate);
      if ($staged_config_update->getTarget() === $event->getConfig()->getName()) {
        $this->delete($staged_config_update);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::SAVE][] = ['onCanvasConfigEntitySave'];
    $events[ConfigEvents::DELETE][] = ['onCanvasConfigDelete'];
    return $events;
  }

  public static function entityIsConsideredNew(ContentEntityInterface|ComponentTreeConfigEntityBase $entity): bool {
    if ($entity instanceof ContentTemplate || $entity instanceof PageVariant) {
      return !$entity->status();
    }
    // Other component-tree config entities (e.g. Pattern) are only ever edited
    // once stored, so they are "new" only while unsaved.
    if ($entity instanceof ComponentTreeConfigEntityBase) {
      return $entity->isNew();
    }
    return (string) $entity->label() == ApiContentControllers::defaultTitle($entity->getEntityType()) || str_ends_with((string) $entity->label(), self::ENTITY_DUPLICATE_SUFFIX);
  }

  /**
   * Sets $current_hash into the stored auto-save item's original_hash key.
   *
   * Callers are responsible for any cache invalidation that follows, since the
   * appropriate scope differs per call site.
   *
   * @param AutoSaveEntry|null $auto_save_item
   */
  private function setStoredEntityHash(string $auto_save_item_key, string $current_hash, ?array $auto_save_item = NULL): void {
    if (\is_null($auto_save_item)) {
      $auto_save_item = $this->autoSaveStore->get($auto_save_item_key);
      \assert(!\is_null($auto_save_item));
    }
    $auto_save_item[self::AUTO_SAVE_STORED_ENTITY_HASH_KEY] = $current_hash;
    $this->autoSaveStore->set($auto_save_item_key, $auto_save_item);
  }

}
