<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Defers content entity saves to kernel terminate for preview API requests.
 */
final class DeferredAutoSaveFlusher implements EventSubscriberInterface {

  /**
   * @var array<string, true>
   */
  private array $queuedKeys = [];

  public function __construct(
    private readonly PendingContentAutoSaveBuffer $buffer,
    private readonly WorkspaceContentEntityPersist $contentEntityPersist,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface|null
     */
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?object $workspaceManager,
    #[Autowire(service: 'lock')]
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
    // The cache holding reconstructed AutoSaveEntity objects; deletes here
    // must target the same backend WorkspaceAutoSave caches into.
    #[Autowire(service: 'canvas.auto_save.entity_memory_cache')]
    private readonly CacheBackendInterface $staticCache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => ['onTerminate', 0],
    ];
  }

  public function enqueue(ContentEntityInterface $entity, ?string $clientId, ?array $entry = NULL): void {
    $key = AutoSaveManager::getAutoSaveKey($entity);
    \assert($entity->id() !== NULL);
    $row = WorkspaceAutoSave::entryMetadata($entry) + [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'langcode' => $entity->language()->getId(),
      'is_default_translation' => $entity->isDefaultTranslation(),
      'data' => AutoSaveManager::toStorableArray($entity),
      'data_hash' => AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($entity)),
      'client_id' => $clientId,
      'token' => \uniqid('pending-', TRUE),
      'updated' => $this->time->getRequestTime(),
      'owner' => (int) $this->currentUser->id(),
    ];
    $this->buffer->set($key, $row);
    $this->queuedKeys[$key] = TRUE;
    $this->staticCache->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
  }

  public function flushNow(EntityInterface $entity): void {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    $this->flushKey(AutoSaveManager::getAutoSaveKey($entity));
  }

  public function onTerminate(): void {
    foreach (\array_keys($this->queuedKeys) as $key) {
      $this->flushKey($key);
    }
    $this->queuedKeys = [];
  }

  private function flushKey(string $key): void {
    $lock_name = 'canvas_auto_save_flush_' . \md5($key);
    if (!$this->lock->acquire($lock_name, 30.0)) {
      return;
    }
    try {
      $row = $this->buffer->get($key);
      // No row, or a client-id tombstone left by an earlier flush: nothing
      // pending.
      if ($row === NULL || !isset($row['token'])) {
        return;
      }
      $token = $row['token'];
      $entity_type = $row['entity_type'] ?? NULL;
      $data = $row['data'] ?? NULL;
      if (!\is_string($entity_type) || !\is_array($data)) {
        return;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $staged = $storage->create($data);
      if (!$staged instanceof ContentEntityInterface) {
        return;
      }
      $staged->enforceIsNew(FALSE);
      $entity_id = $staged->id();
      if ($entity_id === NULL) {
        return;
      }
      $to_save = $storage->loadUnchanged($entity_id);
      if (!$to_save instanceof ContentEntityInterface) {
        $to_save = $storage->load($entity_id);
      }
      if (!$to_save instanceof ContentEntityInterface) {
        return;
      }
      $to_save->enforceIsNew(FALSE);
      // Apply the snapshot onto the translation it was taken from, not onto
      // the default translation.
      $langcode = $row['langcode'] ?? NULL;
      if (\is_string($langcode) && $to_save->hasTranslation($langcode)) {
        $to_save = $to_save->getTranslation($langcode);
      }
      foreach ($staged->getFields() as $field_name => $items) {
        if (!$to_save->hasField($field_name)) {
          continue;
        }
        // Computed fields that are user-editable and persisted on save (e.g.
        // `path`, `moderation_state`) must be applied like stored fields.
        // @see \Drupal\canvas\AutoSave\AutoSaveManager::isPersistedComputedField()
        if ($items->getFieldDefinition()->isComputed() && !AutoSaveManager::isPersistedComputedField($items->getFieldDefinition())) {
          continue;
        }
        $to_save->set($field_name, $items->getValue());
      }
      $this->persistInWorkspace($to_save, $row['client_id'] ?? NULL, self::workspaceIdFromKey($key));
      $this->staticCache->delete($key);
      $this->cacheTagsInvalidator->invalidateTags([AutoSaveManager::CACHE_TAG]);
      $still = $this->buffer->get($key);
      if ($still !== NULL
        && \is_string($still['token'] ?? NULL)
        && \hash_equals((string) $still['token'], (string) $token)) {
        // Replace the flushed row with a tombstone holding only the entry
        // metadata: the staged data now lives in a workspace revision, which
        // cannot record which client instance produced it nor the Live base
        // hash, but concurrent-edit validation and conflict detection need
        // them.
        // @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::getStagedEntryMetadata()
        $this->buffer->set($key, ['client_id' => $row['client_id'] ?? NULL] + WorkspaceAutoSave::entryMetadata($row));
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Canvas deferred auto-save flush failed for @key: @message', [
        '@key' => $key,
        '@message' => $e->getMessage(),
      ]);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * The workspace a buffer row was staged for, recorded in its key prefix.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::getAutoSaveKey()
   */
  private static function workspaceIdFromKey(string $key): string {
    return \explode(':', $key, 2)[0];
  }

  /**
   * Persists inside the recorded workspace, scoped to this operation.
   *
   * The buffer row records the workspace the edit was made in via its key
   * prefix; the flush lands there even when the user has switched workspaces
   * (or none is active) by terminate time. Terminate-time flushes must not
   * leave the workspace active for whatever runs later in the same process.
   */
  private function persistInWorkspace(ContentEntityInterface $entity, ?string $clientId, string $workspaceId): void {
    $persist = fn () => $this->contentEntityPersist->persist($entity, $clientId);
    if ($this->workspaceManager === NULL
      || $this->entityTypeManager->getStorage('workspace')->load($workspaceId) === NULL) {
      $persist();
      return;
    }
    if ($this->workspaceManager->hasActiveWorkspace()
      && $this->workspaceManager->getActiveWorkspace()?->id() === $workspaceId) {
      $persist();
      return;
    }
    $this->workspaceManager->executeInWorkspace($workspaceId, $persist);
  }

}
