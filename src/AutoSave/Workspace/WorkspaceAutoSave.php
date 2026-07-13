<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\AutoSaveEntity;
use Drupal\canvas\Entity\CanvasHttpApiEligibleConfigEntityInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists Canvas auto-save state using the shared workspace and snapshots.
 *
 * Staging for a given entity lives in exactly one place, resolved in this
 * order: the pending write buffer (deferred saves not yet flushed), a payload
 * snapshot row (config entities, and content drafts the storage layer
 * rejected as revisions), or a pending revision tracked in the auto-save
 * workspace. A successful revision persist removes the snapshot row for the
 * same target.
 *
 * Workspace services use untyped optional injection so the container can
 * compile when the Workspaces module is not installed yet.
 */
final class WorkspaceAutoSave {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    // Nullable, resolved to NULL until the Workspaces module is installed
    // (before database updates run); staging then uses the key-value store.
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface|null
     */
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?object $workspaceManager,
    /**
     * @var \Drupal\workspaces\WorkspaceTrackerInterface|null
     */
    #[Autowire(service: 'workspaces.tracker')]
    private readonly ?object $workspaceAssociation,
    private readonly AutoSaveSnapshotRepository $snapshotRepository,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    // MUST be a non-serializing backend; a serializing one (e.g. cache.static)
    // would run cached entities' ::__sleep(), forcing computed fields to
    // compute mid-cache-write and potentially recurse.
    // @see \Drupal\canvas\AutoSave\AutoSaveManager::__construct()
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    #[Autowire(service: 'keyvalue')]
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly AutoSaveRevisionPruner $revisionPruner,
    private readonly WorkspaceContentEntityPersist $contentEntityPersist,
    private readonly PendingContentAutoSaveBuffer $pendingBuffer,
    private readonly DeferredAutoSaveFlusher $deferredFlusher,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Sets revision_created / revision_user when a new pending revision is saved.
   *
   * Entity forms update these via ContentEntityForm; Canvas API saves do not,
   * so revision_timestamp would otherwise stay aligned with an older revision.
   *
   * Runs after workspaces.module's entity_presave, which sets a new revision.
   */
  public function stampAutoSaveWorkspaceRevisionMetadata(EntityInterface $entity): void {
    if (!$entity instanceof RevisionLogInterface || !$entity instanceof ContentEntityInterface) {
      return;
    }
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return;
    }
    /** @var \Drupal\workspaces\WorkspaceInterface $active */
    $active = $this->workspaceManager->getActiveWorkspace();
    if ($active->id() !== AutoSaveWorkspace::ID) {
      return;
    }
    if ($entity->isSyncing()) {
      return;
    }
    if (!$entity->isNewRevision()) {
      return;
    }
    $entity->setRevisionCreationTime($this->time->getRequestTime());
    $entity->setRevisionUserId((int) $this->currentUser->id());
  }

  /**
   * The snapshot target langcode for an entity.
   *
   * Language-less targets (config entities) use LANGCODE_NOT_SPECIFIED, not
   * an empty string: StringItem treats '' as an empty field, which would be
   * stored as NULL, break entity query conditions and (because SQL unique
   * indexes ignore NULL) void the one-row-per-target unique key.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
   */
  public static function snapshotLangcode(EntityInterface $entity): string {
    return $entity instanceof TranslatableInterface ? $entity->language()->getId() : LanguageInterface::LANGCODE_NOT_SPECIFIED;
  }

  public function hasWorkspaceStaging(EntityInterface $entity): bool {
    if ($entity->id() === NULL) {
      return FALSE;
    }
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $buffer_row = $this->pendingBuffer->get($key);
    if ($buffer_row !== NULL && isset($buffer_row['data'])) {
      return TRUE;
    }
    if ($this->snapshotRepository->resolveLatestStaged($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity)) !== NULL) {
      return TRUE;
    }
    if ($entity instanceof ContentEntityInterface
      && $this->workspaceAssociation !== NULL
      && $this->workspaceManager !== NULL) {
      return !$this->loadWorkspaceStagedContentAutoSave($entity)->isEmpty();
    }
    return FALSE;
  }

  /**
   * Whether the entity has an auto-save workspace association row.
   */
  private function isEntityTrackedInAutoSaveWorkspace(EntityInterface $entity): bool {
    if ($this->workspaceAssociation === NULL || $entity->id() === NULL || !$this->snapshotRepository->isWorkspaceStorageReady()) {
      return FALSE;
    }
    $tracked = $this->workspaceAssociation->getTrackedEntities(
      AutoSaveWorkspace::ID,
      $entity->getEntityTypeId(),
      [(string) $entity->id()],
    );
    return !empty($tracked[$entity->getEntityTypeId()]);
  }

  /**
   * Entity to use when building the layout API response (tree + preview HTML).
   */
  public function getEntityForLayoutEditing(ContentEntityInterface $entity): ContentEntityInterface {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if ($this->pendingBuffer->has($key)) {
      $this->cache->delete($key);
    }
    elseif ($this->workspaceAssociation !== NULL && $this->isEntityTrackedInAutoSaveWorkspace($entity)) {
      $this->cache->delete($key);
    }
    $auto_save = $this->loadAutoSaveEntity($entity);
    if (!$auto_save->isEmpty()) {
      \assert($auto_save->entity instanceof ContentEntityInterface);
      return $auto_save->entity;
    }
    if ($this->workspaceManager === NULL || $this->workspaceAssociation === NULL) {
      return $entity;
    }
    if (!$this->isEntityTrackedInAutoSaveWorkspace($entity)) {
      return $entity;
    }
    $id = $entity->id();
    \assert($id !== NULL);
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    $reloaded = $wm->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($entity, $id) {
      $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
      $loaded = $storage->load($id);
      return $loaded instanceof ContentEntityInterface ? $loaded : $entity;
    });
    return $reloaded;
  }

  private function loadWorkspaceStagedContentAutoSave(ContentEntityInterface $entity): AutoSaveEntity {
    if ($this->workspaceManager === NULL || $this->workspaceAssociation === NULL) {
      return AutoSaveEntity::empty();
    }
    if (!$this->isEntityTrackedInAutoSaveWorkspace($entity)) {
      return AutoSaveEntity::empty();
    }
    $id = $entity->id();
    \assert($id !== NULL);
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    $key = AutoSaveManager::getAutoSaveKey($entity);
    return $wm->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($entity, $id, $key, $wm): AutoSaveEntity {
      $storage = $this->entityTypeManager->getStorage($entity->getEntityTypeId());
      $active = $storage->load($id);
      if (!$active instanceof ContentEntityInterface) {
        return AutoSaveEntity::empty();
      }
      $original = $wm->executeOutsideWorkspace(function () use ($storage, $id) {
        $unchanged = $storage->loadUnchanged($id);
        return $unchanged instanceof ContentEntityInterface ? $unchanged : $storage->load($id);
      });
      if (!$original instanceof ContentEntityInterface) {
        return AutoSaveEntity::empty();
      }
      // Auto-save entries are per translation: compare and return the
      // translation matching the requested entity's language.
      // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
      $langcode = $entity->language()->getId();
      if ($active->hasTranslation($langcode)) {
        $active = $active->getTranslation($langcode);
      }
      if ($original->hasTranslation($langcode)) {
        $original = $original->getTranslation($langcode);
      }
      $this->applyRecordedDraftPath($active, $key);
      $hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($active));
      $unchanged_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($original));
      if (\hash_equals($unchanged_hash, $hash)) {
        return AutoSaveEntity::empty();
      }
      $auto_save_entity = new AutoSaveEntity($active, $hash, $this->getStagedClientId($key), $this->stagedRevisionTime($active));
      $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
      return $auto_save_entity;
    });
  }

  public function importLegacyArray(EntityInterface $entity, array $legacy): void {
    \assert(isset($legacy['data']) && \is_array($legacy['data']));
    $storage = $this->entityTypeManager->getStorage($legacy['entity_type']);
    $staged = $storage->create($legacy['data']);
    \assert($staged instanceof EntityInterface);
    // The staged draft targets $entity: enforce its identity, or the persist
    // would create a new entity instead of staging a revision of the existing
    // one. ::create() marks the reconstruction as new even when the legacy
    // data carries the id, and pre-1.0 legacy rows may lack the id entirely.
    if ($staged instanceof ContentEntityInterface && $entity instanceof ContentEntityInterface) {
      $entity_type = $storage->getEntityType();
      foreach (['id', 'uuid', 'revision'] as $key_name) {
        $key = $entity_type->getKey($key_name);
        if (\is_string($key) && $key !== '' && $staged->get($key)->isEmpty() && !$entity->get($key)->isEmpty()) {
          $staged->set($key, $entity->get($key)->value);
        }
      }
      $staged->enforceIsNew(FALSE);
      $staged->updateLoadedRevisionId();
      // ::create() pre-marks the entity as a new revision, which makes the
      // later setNewRevision(TRUE) in workspaces' entity_presave a no-op
      // that skips clearing the revision key; the save would then insert a
      // duplicate of the grafted revision id. Reset the flag so that
      // transition runs and a fresh revision id is assigned.
      $staged->setNewRevision(FALSE);
    }
    // Pass the legacy entry through so its metadata (owner, updated,
    // original_hash, conflict retention) survives the migration.
    $this->persistStagedEntity($staged, $legacy['client_id'] ?? NULL, TRUE, $legacy);
  }

  /**
   * @param array<string, mixed>|null $entry
   *   The full auto-save entry as built by AutoSaveManager::saveEntity()
   *   (data, langcode, is_default_translation, original_hash, conflict
   *   retention, …). Stored verbatim by key-value-backed staging so 1.x
   *   consumers (conflict detection, symmetric translation) keep their data.
   */
  public function persistStagedEntity(EntityInterface $entity, ?string $clientId, bool $immediateContentPersist = FALSE, ?array $entry = NULL): void {
    if ($this->workspaceManager === NULL) {
      throw new \RuntimeException('Canvas auto-save requires the Workspaces module.');
    }

    if ($this->usesKeyValueStaging($entity)) {
      $this->persistLegacyKeyValue($entity, $clientId, $entry);
      return;
    }

    // Scope the workspace context to the persist operation: permanently
    // activating the workspace would leak into subsequent entity saves in the
    // same process (CLI, tests, long-running workers).
    $this->snapshotRepository->executeInAutoSaveWorkspace(function () use ($entity, $clientId, $immediateContentPersist, $entry): void {
      if ($entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
        $this->persistConfigSnapshot($entity, $clientId);
        return;
      }
      if ($entity instanceof ContentEntityInterface) {
        $this->persistContentEntity($entity, $clientId, $immediateContentPersist, $entry);
        return;
      }
      throw new \InvalidArgumentException('Unsupported entity for workspace auto-save.');
    });
  }

  /**
   * Whether $entity's drafts stage in the key-value store, not the workspace.
   *
   * TRUE when:
   * - the workspace entity schema is not installed yet (kernel tests, or
   *   before database updates run),
   * - the shared auto-save workspace has not been provisioned yet, or
   * - the entity is a config entity without snapshot support (e.g.
   *   StagedLanguageConfigOverride), whose drafts are key-value entries that
   *   AutoSaveManager::groupConfigEntityAutoSaves() consumes.
   *
   * This single predicate keeps persisting, loading and legacy migration in
   * agreement about where a given entity's draft lives.
   */
  public function usesKeyValueStaging(EntityInterface $entity): bool {
    if ($this->workspaceManager === NULL || !$this->snapshotRepository->isWorkspaceStorageReady()) {
      return TRUE;
    }
    if ($entity instanceof ConfigEntityInterface && !$entity instanceof CanvasHttpApiEligibleConfigEntityInterface) {
      return TRUE;
    }
    return $this->entityTypeManager->getStorage('workspace')->load(AutoSaveWorkspace::ID) === NULL;
  }

  /**
   * Key-value staging for config entities without snapshot support.
   *
   * StagedLanguageConfigOverride (and any other non-HTTP-API-eligible config
   * entity) has no snapshot support; its drafts stage as key-value entries
   * that AutoSaveManager::groupConfigEntityAutoSaves() consumes.
   */
  private function persistLegacyKeyValue(EntityInterface $entity, ?string $clientId, ?array $entry = NULL): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if ($entry === NULL) {
      $data = AutoSaveManager::normalizeEntity($entity);
      $data_hash = AutoSaveManager::generateHashFromData($data);
      $entry = [
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => $entity->id(),
        'data' => AutoSaveManager::toStorableArray($entity),
        'langcode' => $entity->language()->getId(),
        'is_default_translation' => !($entity instanceof TranslatableInterface) || $entity->isDefaultTranslation(),
        'label' => (string) $entity->label(),
        'original_hash' => NULL,
        'data_hash' => $data_hash,
        'client_id' => $clientId,
        'owner' => (int) $this->currentUser->id(),
        'updated' => $this->time->getRequestTime(),
      ];
    }
    $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->set($key, $entry);
    $this->cache->delete($key);
  }

  private function persistConfigSnapshot(CanvasHttpApiEligibleConfigEntityInterface $entity, ?string $clientId): void {
    $payload = \json_encode($entity->toArray(), JSON_THROW_ON_ERROR);
    $data_hash = AutoSaveManager::generateHashFromData(\json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR));
    $this->snapshotRepository->persist(
      $entity->getEntityTypeId(),
      (string) $entity->id(),
      self::snapshotLangcode($entity),
      $payload,
      $data_hash,
      $clientId,
      (int) $this->currentUser->id(),
    );
  }

  private function persistContentEntity(ContentEntityInterface $entity, ?string $clientId, bool $immediateContentPersist, ?array $entry = NULL): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $use_immediate = $immediateContentPersist || !$this->shouldDeferContentPersistToTerminate();
    if ($use_immediate) {
      $this->contentEntityPersist->persist($entity, $clientId);
      // Workspace revisions cannot record which client instance produced the
      // draft nor the hash of the Live base it started from, but
      // concurrent-edit validation and conflict detection need both.
      // @see ::getStagedClientId()
      // @see ::getStagedEntryMetadata()
      $this->pendingBuffer->set($key, ['client_id' => $clientId] + self::entryMetadata($entry));
      return;
    }
    $this->deferredFlusher->enqueue($entity, $clientId, $entry);
  }

  /**
   * Conflict-detection metadata to carry alongside workspace staging.
   *
   * @param array<string, mixed>|null $entry
   *
   * @return array<string, mixed>
   */
  public static function entryMetadata(?array $entry): array {
    $metadata = \array_intersect_key($entry ?? [], \array_flip([
      'original_hash',
      'owner',
      'updated',
      AutoSaveManager::AUTO_SAVE_CONFLICT_KEY,
      self::DRAFT_PATH_KEY,
    ]));
    // Record the draft's `path` value verbatim: on a staged revision the
    // computed path field resolves through alias storage, which cannot
    // represent a draft that cleared (or never set) its alias.
    // @see ::applyRecordedDraftPath()
    if (!\array_key_exists(self::DRAFT_PATH_KEY, $metadata) && isset($entry['data']) && \is_array($entry['data'])) {
      $metadata[self::DRAFT_PATH_KEY] = $entry['data']['path'] ?? [];
    }
    return $metadata;
  }

  /**
   * Metadata key holding a content draft's verbatim `path` field value.
   */
  public const string DRAFT_PATH_KEY = 'draft_path';

  /**
   * Overrides a staged entity's computed path with the recorded draft value.
   *
   * The alias lookup powering the computed path field is not revision-aware:
   * inside the workspace it resolves the staged alias, and a draft that
   * cleared its alias would still present the previously staged (or Live)
   * one. The verbatim value recorded at staging time is authoritative.
   */
  private function applyRecordedDraftPath(ContentEntityInterface $entity, string $key): void {
    if (!$entity->hasField('path')) {
      return;
    }
    $metadata = $this->getStagedEntryMetadata($key);
    if (\is_array($metadata) && \array_key_exists(self::DRAFT_PATH_KEY, $metadata)) {
      $draft_path = $metadata[self::DRAFT_PATH_KEY];
      if (!$draft_path) {
        // A cleared alias is recorded as an empty value; explicit NULL resets
        // the computed path field instead of assigning the empty value.
        $draft_path = NULL;
      }
      $entity->set('path', $draft_path);
    }
  }

  /**
   * The client instance id that produced the workspace-staged draft, if known.
   */
  private function getStagedClientId(string $key): ?string {
    $client_id = $this->getStagedEntryMetadata($key)['client_id'] ?? NULL;
    return \is_string($client_id) ? $client_id : NULL;
  }

  /**
   * Auto-save entry metadata recorded alongside workspace staging.
   *
   * @return array<string, mixed>|null
   *   The recorded metadata (client_id, original_hash, conflict retention),
   *   or NULL when nothing is recorded for $key.
   */
  public function getStagedEntryMetadata(string $key): ?array {
    return $this->pendingBuffer->get($key);
  }

  /**
   * Advances the recorded stored-entity hash after a conflict resolution.
   *
   * Key-value-staged entries carry the hash in the entry itself;
   * workspace-staged entries record it in the staging metadata.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::resolveConflict()
   */
  public function advanceStagedEntryOriginalHash(EntityInterface $entity, string $hash): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $kv = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $row = $kv->get($key);
    if (\is_array($row)) {
      $row[AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY] = $hash;
      $kv->set($key, $row);
    }
    else {
      $this->pendingBuffer->set($key, [AutoSaveManager::AUTO_SAVE_STORED_ENTITY_HASH_KEY => $hash] + ($this->pendingBuffer->get($key) ?? []));
    }
    $this->cache->delete($key);
  }

  /**
   * Defers DB writes for Canvas API routes (e.g. layout preview PATCH) only.
   *
   * CLI, Drush, and kernel tests without a matching route use immediate
   * persist.
   * Set CANVAS_TEST_FORCE_DEFER_AUTOSAVE=1 to exercise defer in unit tests.
   */
  private function shouldDeferContentPersistToTerminate(): bool {
    $force = \getenv('CANVAS_TEST_FORCE_DEFER_AUTOSAVE');
    if ($force === '1' || $force === 'true') {
      return TRUE;
    }
    $name = $this->routeMatch->getRouteName();
    return \is_string($name) && \str_starts_with($name, 'canvas.api.');
  }

  public function loadAutoSaveEntity(EntityInterface $entity, bool $bypassCache = FALSE): AutoSaveEntity {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    if (!$bypassCache) {
      $cached = $this->cache->get($key);
      if ($cached) {
        \assert($cached->data instanceof AutoSaveEntity);
        return $cached->data;
      }
    }

    if ($this->workspaceManager === NULL || $this->usesKeyValueStaging($entity)) {
      // @see ::persistStagedEntity()
      return $this->loadKeyValueAutoSaveEntity($entity);
    }

    // Staging resolves in a fixed order for every entity type: the pending
    // write buffer, then a snapshot row, then a workspace-tracked revision.
    // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceContentEntityPersist
    $pending = $this->loadPendingContentAutoSave($entity);
    if ($pending !== NULL) {
      $this->cache->set($key, $pending, tags: [AutoSaveManager::CACHE_TAG]);
      return $pending;
    }

    if ($entity->id() !== NULL) {
      $snapshot = $this->snapshotRepository->resolveLatestStaged($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity));
      if ($snapshot !== NULL) {
        $data = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
        $staged = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->create($data);
        $auto_save_entity = new AutoSaveEntity($staged, $snapshot->getDataHash(), $snapshot->getClientInstanceId(), (int) ($snapshot->getChangedTime() ?? $this->time->getRequestTime()));
        $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
        return $auto_save_entity;
      }
    }

    if ($entity instanceof ContentEntityInterface && $this->workspaceAssociation !== NULL) {
      return $this->loadWorkspaceStagedContentAutoSave($entity);
    }

    return AutoSaveEntity::empty();
  }

  /**
   * Loads a key-value-staged draft.
   *
   * @see ::persistLegacyKeyValue()
   */
  private function loadKeyValueAutoSaveEntity(EntityInterface $entity): AutoSaveEntity {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $auto_save_data = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->get($key);
    if (!\is_array($auto_save_data) || !isset($auto_save_data['data'], $auto_save_data['entity_type'])) {
      return AutoSaveEntity::empty();
    }
    $auto_save_entity = new AutoSaveEntity(
      $this->entityTypeManager->getStorage($auto_save_data['entity_type'])->create($auto_save_data['data']),
      $auto_save_data['data_hash'],
      $auto_save_data['client_id'] ?? NULL,
      isset($auto_save_data['updated']) ? (int) $auto_save_data['updated'] : NULL,
    );
    $this->cache->set($key, $auto_save_entity, tags: [AutoSaveManager::CACHE_TAG]);
    return $auto_save_entity;
  }

  private function loadPendingContentAutoSave(EntityInterface $entity): ?AutoSaveEntity {
    if (!$entity instanceof ContentEntityInterface || $entity->id() === NULL || $this->workspaceManager === NULL) {
      return NULL;
    }
    $row = $this->pendingBuffer->get(AutoSaveManager::getAutoSaveKey($entity));
    if ($row === NULL || !isset($row['entity_type'], $row['data'], $row['data_hash'])) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage($row['entity_type']);
    $staged = $storage->create($row['data']);
    return new AutoSaveEntity($staged, $row['data_hash'], $row['client_id'] ?? NULL, isset($row['updated']) ? (int) $row['updated'] : NULL);
  }

  /**
   * @return array<string, array<string, mixed>>
   */
  public function getAllList(): array {
    /** @var array<string, array<string, mixed>> $out */
    $out = [];
    foreach ($this->snapshotRepository->loadAll() as $snapshot) {
      $data = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
      // Some labels are derived (e.g. PageRegion), so an unsaved entity object
      // is needed to compute the label the way the entity type defines it.
      $staged = $this->entityTypeManager->getStorage($snapshot->getTargetEntityTypeId())->create($data);
      // Derive the key from the staged entity so it matches getAutoSaveKey()
      // exactly (config keys carry no langcode, content keys always do).
      $key = AutoSaveManager::getAutoSaveKey($staged);
      $out[$key] = [
        'entity_type' => $snapshot->getTargetEntityTypeId(),
        'entity_id' => $snapshot->getTargetEntityId(),
        'data' => $data,
        'langcode' => $staged->language()->getId(),
        'is_default_translation' => !($staged instanceof TranslatableInterface) || $staged->isDefaultTranslation(),
        'label' => self::labelForAutoSaveList($staged),
        'data_hash' => $snapshot->getDataHash(),
        'client_id' => $snapshot->getClientInstanceId(),
        'owner' => (int) $snapshot->getOwnerId(),
        'updated' => (int) ($snapshot->getChangedTime() ?? $this->time->getRequestTime()),
      ];
    }

    $this->appendKeyValueEntries($out);
    $this->appendWorkspaceTrackedContentEntities($out);
    $this->appendPendingBufferEntities($out);

    \ksort($out);
    return $out;
  }

  /**
   * Adds key-value-staged drafts (pre-migration rows and snapshot-less config).
   *
   * Existing keys win: snapshot-backed entries take precedence over a stale
   * key-value row for the same target.
   */
  private function appendKeyValueEntries(array &$out): void {
    $kv = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    foreach ($kv->getAll() as $kv_key => $entry) {
      if (isset($out[$kv_key])) {
        continue;
      }
      // Rows without entity data are metadata only (e.g. a resolved-conflict
      // marker for a workspace-staged draft); they overlay the corresponding
      // workspace-derived entry instead of being listed themselves.
      // @see ::appendWorkspaceTrackedContentEntities()
      if (!isset($entry['entity_type'], $entry['data'])) {
        continue;
      }
      $out[$kv_key] = $entry;
      $out[$kv_key]['owner'] = \is_numeric($out[$kv_key]['owner'] ?? NULL) ? (int) ($out[$kv_key]['owner']) : 0;
    }
  }

  /**
   * Adds content entities present only in the pending (pre-revision) buffer.
   *
   * @param array<string, array<string, mixed>> $out
   */
  private function appendPendingBufferEntities(array &$out): void {
    if ($this->workspaceManager === NULL) {
      return;
    }
    foreach ($this->pendingBuffer->getAll() as $kv_key => $row) {
      if (isset($out[$kv_key])) {
        continue;
      }
      if (!isset($row['entity_type'], $row['entity_id'], $row['data'], $row['data_hash'])) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($row['entity_type']);
      $created = $storage->create($row['data']);
      $langcode = $row['langcode'] ?? NULL;
      $out[$kv_key] = self::entryMetadata($row) + [
        'entity_type' => $row['entity_type'],
        'entity_id' => $row['entity_id'],
        'data' => $row['data'],
        'langcode' => $langcode,
        'is_default_translation' => $row['is_default_translation'] ?? TRUE,
        'label' => self::labelForAutoSaveList($created),
        'data_hash' => $row['data_hash'],
        'client_id' => $row['client_id'] ?? NULL,
        'owner' => (int) ($row['owner'] ?? 0),
        'updated' => (int) ($row['updated'] ?? $this->time->getRequestTime()),
      ];
    }
  }

  /**
   * Reconstructs all staged drafts of one entity type, unsaved.
   *
   * Config entity drafts live in snapshot rows or (pre-migration, or without
   * workspace infrastructure) key-value rows, so this read never activates
   * the auto-save workspace. That matters for callers reacting to events
   * triggered by users without workspace view access, e.g. the config-delete
   * hook firing for arbitrary config deletions.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::onCanvasConfigDelete()
   */
  public function loadStagedEntitiesOfType(string $entity_type_id): array {
    $entities = [];
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    foreach ($this->snapshotRepository->loadAll() as $snapshot) {
      if ($snapshot->getTargetEntityTypeId() !== $entity_type_id) {
        continue;
      }
      $data = \json_decode($snapshot->getPayload(), TRUE, 512, JSON_THROW_ON_ERROR);
      $entities[] = $storage->create($data);
    }
    foreach ($this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->getAll() as $entry) {
      if (($entry['entity_type'] ?? NULL) !== $entity_type_id || !isset($entry['data']) || !\is_array($entry['data'])) {
        continue;
      }
      $entities[] = $storage->create($entry['data']);
    }
    return $entities;
  }

  /**
   * Adds content staged only in the auto-save workspace (no KV/snapshot).
   *
   * Node and other content entities are persisted via $entity->save() in the
   * workspace without legacy key-value entries, so the pending list must read
   * workspace association data to match the client "changed" state.
   *
   * @param array<string, array<string, mixed>> $out
   */
  private function appendWorkspaceTrackedContentEntities(array &$out): void {
    if ($this->workspaceManager === NULL || $this->workspaceAssociation === NULL || !$this->snapshotRepository->isWorkspaceStorageReady()) {
      return;
    }
    $workspace = $this->entityTypeManager->getStorage('workspace')->load(AutoSaveWorkspace::ID);
    if ($workspace === NULL) {
      return;
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    // The workspace must be active for this read: computed fields on staged
    // revisions (e.g. a page's path alias, staged as a dependent path_alias
    // entity) only resolve to their staged values inside the workspace, and
    // the emitted data_hash must match what per-entity staging reads produce.
    $wm->executeInWorkspace(AutoSaveWorkspace::ID, function () use (&$out, $wm): void {
      $tracked = $this->workspaceAssociation->getTrackedEntities(AutoSaveWorkspace::ID);
      foreach ($tracked as $entity_type_id => $revision_map) {
        // Entities implicitly staged alongside a host item (e.g. the URL
        // alias written when a page with a changed path is staged) are not
        // pending changes of their own: they follow their host item through
        // publish and discard.
        // @see ::discardWorkspaceStagedContentEntity()
        if (\in_array($entity_type_id, self::DEPENDENT_ENTITY_TYPE_IDS, TRUE)) {
          continue;
        }
        foreach ($revision_map as $entity_id) {
          $storage = $this->entityTypeManager->getStorage($entity_type_id);
          $entity = $storage->load($entity_id);
          if (!$entity instanceof ContentEntityInterface) {
            continue;
          }
          $canonical = $wm->executeOutsideWorkspace(function () use ($storage, $entity_id) {
            $unchanged = $storage->loadUnchanged($entity_id);
            return $unchanged instanceof ContentEntityInterface ? $unchanged : $storage->load($entity_id);
          });
          // Auto-save entries are per translation: emit one entry for every
          // translation whose staged state differs from the canonical one.
          // @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
          foreach (\array_keys($entity->getTranslationLanguages()) as $langcode) {
            $translation = $entity->getTranslation($langcode);
            $key = AutoSaveManager::getAutoSaveKey($translation);
            if (isset($out[$key])) {
              continue;
            }
            $this->applyRecordedDraftPath($translation, $key);
            $data_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($translation));
            if ($canonical instanceof ContentEntityInterface && $canonical->hasTranslation($langcode)) {
              $canonical_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($canonical->getTranslation($langcode)));
              if (\hash_equals($canonical_hash, $data_hash)) {
                continue;
              }
            }
            $kv_row = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->get($key);
            $metadata = self::entryMetadata(\is_array($kv_row) ? $kv_row : NULL) + self::entryMetadata($this->getStagedEntryMetadata($key));
            // Already applied to $translation above; not a list row property.
            unset($metadata[self::DRAFT_PATH_KEY]);
            $out[$key] = $metadata + [
              'entity_type' => $translation->getEntityTypeId(),
              'entity_id' => $translation->id(),
              'data' => AutoSaveManager::toStorableArray($translation),
              'langcode' => $langcode,
              'is_default_translation' => $translation->isDefaultTranslation(),
              'label' => self::labelForAutoSaveList($translation),
              'data_hash' => $data_hash,
              'client_id' => $this->getStagedClientId($key),
              'owner' => self::stagedRevisionOwner($translation),
              'updated' => $this->stagedRevisionTime($translation),
            ];
          }
        }
      }
    });
  }

  /**
   * Entity types staged only as dependents of a host item, never on their own.
   *
   * @var list<string>
   */
  private const DEPENDENT_ENTITY_TYPE_IDS = ['path_alias'];

  /**
   * The editor recorded on the staged revision, falling back to the owner.
   *
   * @see ::stampAutoSaveWorkspaceRevisionMetadata()
   */
  private static function stagedRevisionOwner(ContentEntityInterface $entity): int {
    if ($entity instanceof RevisionLogInterface) {
      $revision_user = $entity->getRevisionUserId();
      if ($revision_user !== NULL) {
        return (int) $revision_user;
      }
    }
    return $entity instanceof EntityOwnerInterface ? (int) ($entity->getOwnerId() ?? 0) : 0;
  }

  /**
   * The time recorded on the staged revision, falling back to request time.
   *
   * @see ::stampAutoSaveWorkspaceRevisionMetadata()
   */
  private function stagedRevisionTime(ContentEntityInterface $entity): int {
    if ($entity instanceof RevisionLogInterface) {
      $revision_time = $entity->getRevisionCreationTime();
      if ($revision_time !== NULL) {
        return (int) $revision_time;
      }
    }
    return (int) $this->time->getRequestTime();
  }

  /**
   * Human-readable label for GET /auto-saves/pending (OpenAPI non-null).
   */
  private static function labelForAutoSaveList(EntityInterface $entity): string {
    $label = $entity->label();
    if ($label === NULL || $label === '') {
      return (string) $entity->id();
    }
    return (string) $label;
  }

  /**
   * Deletes pending workspace revisions so discard/publish can clear staging.
   */
  private function discardWorkspaceStagedContentEntity(EntityInterface $entity): void {
    if ($this->workspaceManager === NULL || $this->workspaceAssociation === NULL) {
      return;
    }
    if (!$entity instanceof ContentEntityInterface || $entity->id() === NULL) {
      return;
    }
    $this->pendingBuffer->delete(AutoSaveManager::getAutoSaveKey($entity));
    if (!$this->isEntityTrackedInAutoSaveWorkspace($entity)) {
      return;
    }
    $this->discardTrackedRevisions($entity->getEntityTypeId(), (string) $entity->id());
    $this->discardDependentStagedEntities($entity);
    $this->revisionPruner->reset($entity);
  }

  /**
   * Deletes every tracked pending revision of one entity from the workspace.
   */
  private function discardTrackedRevisions(string $type_id, string $eid): void {
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    /** @var \Drupal\workspaces\WorkspaceTrackerInterface $tracker */
    $tracker = $this->workspaceAssociation;
    $wm->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($type_id, $eid, $tracker): void {
      $storage = $this->entityTypeManager->getStorage($type_id);
      if (!$storage instanceof RevisionableStorageInterface) {
        return;
      }
      $tracked = $tracker->getTrackedEntities(AutoSaveWorkspace::ID, $type_id, [$eid]);
      if (empty($tracked[$type_id])) {
        return;
      }
      foreach (\array_keys($tracked[$type_id]) as $revision_id) {
        $storage->deleteRevision($revision_id);
      }
    });
  }

  /**
   * Discards staged dependent entities (e.g. path aliases) of a host item.
   *
   * Staging a host entity inside the workspace also stages entities it
   * implicitly edits; when the host's staging is cleared, theirs must be too,
   * or they linger tracked (and exclusive-edit locked) with no owner.
   */
  private function discardDependentStagedEntities(ContentEntityInterface $entity): void {
    if ($this->workspaceAssociation === NULL) {
      return;
    }
    try {
      $host_path = '/' . $entity->toUrl()->getInternalPath();
    }
    catch (\Exception) {
      // Entities without a canonical route cannot have aliases.
      return;
    }
    foreach (self::DEPENDENT_ENTITY_TYPE_IDS as $dependent_type_id) {
      if (!$this->entityTypeManager->hasDefinition($dependent_type_id)) {
        continue;
      }
      $tracked = $this->workspaceAssociation->getTrackedEntities(AutoSaveWorkspace::ID, $dependent_type_id);
      if (empty($tracked[$dependent_type_id])) {
        continue;
      }
      /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
      $wm = $this->workspaceManager;
      $dependent_ids = $wm->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($dependent_type_id, $tracked, $host_path): array {
        $ids = [];
        $storage = $this->entityTypeManager->getStorage($dependent_type_id);
        foreach (\array_unique($tracked[$dependent_type_id]) as $dependent_id) {
          $dependent = $storage->load($dependent_id);
          if ($dependent !== NULL && $dependent->hasField('path') && $dependent->get('path')->value === $host_path) {
            $ids[] = (string) $dependent_id;
          }
        }
        return $ids;
      });
      foreach ($dependent_ids as $dependent_id) {
        $this->discardTrackedRevisions($dependent_type_id, $dependent_id);
      }
    }
  }

  /**
   * Publishes staged dependent entities (e.g. path aliases) of a host item.
   *
   * A path alias created while staging is a new path_alias entity whose Live
   * (default) revision core Workspaces keeps unpublished so it stays
   * invisible outside the workspace. Canvas publishes hosts selectively (it
   * never calls Workspace::publish()), so when a host goes live its dependent
   * entities must be published explicitly; core's workspace-level publish
   * would otherwise have done this.
   *
   * Call after the host entity has been saved to Live: that save (e.g.
   * PathItem::postSave()) already wrote the staged field values to the
   * dependent's Live revision, so only its published status is missing.
   *
   * @see ::discardDependentStagedEntities()
   * @see \Drupal\workspaces\Hook\EntityOperations::entityPresave()
   */
  public function publishDependentStagedEntities(ContentEntityInterface $entity): void {
    if ($this->workspaceManager === NULL) {
      return;
    }
    try {
      $host_path = '/' . $entity->toUrl()->getInternalPath();
    }
    catch (\Exception) {
      // Entities without a canonical route cannot have aliases.
      return;
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    foreach (self::DEPENDENT_ENTITY_TYPE_IDS as $dependent_type_id) {
      if (!$this->entityTypeManager->hasDefinition($dependent_type_id)) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($dependent_type_id);
      // The whole read-check-write must happen outside the workspace: the
      // auto-save workspace is active during Canvas API requests, and inside
      // it loads resolve to the pending revision, which core created as
      // published; only the Live default revision carries the unpublished
      // marker this method must lift.
      $wm->executeOutsideWorkspace(static function () use ($storage, $host_path): void {
        $ids = $storage->getQuery()
          ->accessCheck(FALSE)
          ->condition('path', $host_path)
          ->execute();
        foreach ($storage->loadMultiple($ids) as $dependent) {
          if ($dependent instanceof EntityPublishedInterface && !$dependent->isPublished()) {
            $dependent->setPublished();
            $dependent->save();
          }
        }
      });
    }
  }

  public function deleteEntity(EntityInterface $entity): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $this->pendingBuffer->delete($key);
    if ($entity->id() !== NULL) {
      $this->snapshotRepository->deleteFor($entity->getEntityTypeId(), (string) $entity->id(), self::snapshotLangcode($entity));
    }
    $this->discardWorkspaceStagedContentEntity($entity);
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
    $this->cache->delete($key);
    $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->delete($key);
  }

  /**
   * Persists any pending (pre-terminate) auto-save buffer for a content entity.
   *
   * Call before returning autoSave hashes to the client so
   * autoSaveStartingPoint matches workspace revisions (buffer tokens are only
   * valid until flush).
   */
  public function flushDeferredContentEntity(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $this->deferredFlusher->flushNow($entity);
  }

  public function deleteAll(): void {
    $this->snapshotRepository->deleteAll();
    // Workspace-tracked staged revisions are staging too: discard them, or
    // "discard all" leaves pending changes that reappear on the next listing.
    if ($this->workspaceAssociation !== NULL && $this->workspaceManager !== NULL && $this->snapshotRepository->isWorkspaceStorageReady()) {
      $tracked = $this->workspaceAssociation->getTrackedEntities(AutoSaveWorkspace::ID);
      foreach ($tracked as $entity_type_id => $revision_map) {
        foreach (\array_unique($revision_map) as $entity_id) {
          $this->discardTrackedRevisions($entity_type_id, (string) $entity_id);
          $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);
          if ($entity !== NULL) {
            $this->revisionPruner->reset($entity);
          }
        }
      }
    }
    $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE)->deleteAll();
    $this->pendingBuffer->deleteAll();
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
  }

  /**
   * Opaque token for concurrent-edit checks.
   *
   * Always derived from the Live copy: the starting point identifies the base
   * an auto-save draft started from, so it must stay stable across successive
   * auto-saves (snapshot rows and pending-buffer tokens change on every save)
   * and only change when the entity itself is saved.
   */
  public function getAutoSaveStartingPoint(EntityInterface $entity): string|int|null {
    \assert($entity->id() !== NULL);
    // Load outside the auto-save workspace: with it active, loadUnchanged()
    // would return the staged revision, shifting the starting point on every
    // auto-save.
    $saved_entity = $this->loadUnchangedOutsideWorkspace($entity->getEntityTypeId(), (string) $entity->id());
    \assert($saved_entity instanceof EntityInterface);
    $auto_save_start_revision = $saved_entity instanceof RevisionableInterface
      ? $saved_entity->getRevisionId()
      : \hash('xxh64', \json_encode($saved_entity->toArray(), JSON_THROW_ON_ERROR));
    if ($saved_entity instanceof EntityChangedInterface) {
      $auto_save_start_revision .= '-' . $saved_entity->getChangedTime();
    }
    return $auto_save_start_revision;
  }

  /**
   * Loads the Live (outside any workspace) unchanged copy of an entity.
   *
   * During Canvas API requests the auto-save workspace is active, so a plain
   * loadUnchanged() would return the staged revision rather than the Live
   * base that hashes and starting points must be computed against.
   */
  public function loadUnchangedOutsideWorkspace(string $entityTypeId, string|int $id): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage($entityTypeId);
    if ($this->workspaceManager === NULL) {
      return $storage->loadUnchanged($id);
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    return $wm->executeOutsideWorkspace(static fn () => $storage->loadUnchanged($id));
  }

}
