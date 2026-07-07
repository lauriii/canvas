<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\Entity\CanvasAutoSaveSnapshot;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes payload snapshot rows, one per staged target.
 *
 * Snapshots are workspace-ignored (see CanvasAutoSaveSnapshot), so reads and
 * writes here behave the same regardless of the caller's workspace context.
 */
final class AutoSaveSnapshotRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityLastInstalledSchemaRepositoryInterface $entityLastInstalledSchemaRepository,
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface|null
     */
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?object $workspaceManager = NULL,
  ) {}

  /**
   * TRUE when the snapshot entity schema is installed (not just defined).
   *
   * During multi-module install, core.extension may list canvas before its
   * `installFieldableEntityType()` pass runs, so hasDefinition() alone is
   * unsafe.
   */
  public function isStagedStorageReady(): bool {
    if (!$this->entityTypeManager->hasDefinition(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID)) {
      return FALSE;
    }
    return $this->entityLastInstalledSchemaRepository->getLastInstalledDefinition(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID) !== NULL;
  }

  /**
   * TRUE when the workspace entity schema is installed (not just defined).
   *
   * The Workspaces module can be enabled while its entity schema is not yet
   * installed (kernel tests, or before database updates run); workspace-backed
   * staging must fall back to the key-value store until it is.
   */
  public function isWorkspaceStorageReady(): bool {
    if (!$this->entityTypeManager->hasDefinition('workspace')) {
      return FALSE;
    }
    return $this->entityLastInstalledSchemaRepository->getLastInstalledDefinition('workspace') !== NULL;
  }

  public function resolveLatestStaged(string $targetEntityTypeId, string $targetEntityId, string $targetLangcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): ?CanvasAutoSaveSnapshot {
    if (!$this->isStagedStorageReady()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('target_entity_type_id', $targetEntityTypeId)
      ->condition('target_entity_id', $targetEntityId)
      ->condition('target_langcode', $targetLangcode)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $entity = $storage->load(reset($ids));
    return $entity instanceof CanvasAutoSaveSnapshot ? $entity : NULL;
  }

  /**
   * Creates or updates the single snapshot row for a target.
   *
   * The unique key over (type, id, langcode) makes concurrent first saves
   * race-safe: the loser of the insert race retries as an update.
   *
   * @see \Drupal\canvas\Entity\Storage\CanvasAutoSaveSnapshotStorageSchema
   */
  public function persist(string $targetEntityTypeId, string $targetEntityId, string $targetLangcode, string $payload, string $dataHash, ?string $clientId, int $ownerId): CanvasAutoSaveSnapshot {
    if (!$this->isStagedStorageReady()) {
      throw new \RuntimeException('The canvas_auto_save_snapshot entity schema must be installed (run database updates).');
    }
    $existing = $this->resolveLatestStaged($targetEntityTypeId, $targetEntityId, $targetLangcode);
    if ($existing === NULL) {
      $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
      $snapshot = $storage->create([
        'target_entity_type_id' => $targetEntityTypeId,
        'target_entity_id' => $targetEntityId,
        'target_langcode' => $targetLangcode,
        'payload' => $payload,
        'data_hash' => $dataHash,
        'client_instance_id' => $clientId,
        'uid' => $ownerId,
      ]);
      \assert($snapshot instanceof CanvasAutoSaveSnapshot);
      try {
        $snapshot->save();
        return $snapshot;
      }
      catch (EntityStorageException) {
        // Lost the insert race against a concurrent first save.
        $existing = $this->resolveLatestStaged($targetEntityTypeId, $targetEntityId, $targetLangcode);
        if ($existing === NULL) {
          throw new \RuntimeException(\sprintf('Unable to persist auto-save snapshot for %s:%s.', $targetEntityTypeId, $targetEntityId));
        }
      }
    }
    $existing->set('payload', $payload);
    $existing->set('data_hash', $dataHash);
    $existing->set('client_instance_id', $clientId);
    $existing->setOwnerId($ownerId);
    $existing->save();
    return $existing;
  }

  public function deleteFor(string $targetEntityTypeId, string $targetEntityId, string $targetLangcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): void {
    $snapshot = $this->resolveLatestStaged($targetEntityTypeId, $targetEntityId, $targetLangcode);
    $snapshot?->delete();
  }

  /**
   * @return \Drupal\canvas\Entity\CanvasAutoSaveSnapshot[]
   */
  public function loadAll(): array {
    if (!$this->isStagedStorageReady()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
    /** @var \Drupal\canvas\Entity\CanvasAutoSaveSnapshot[] */
    return $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->execute());
  }

  public function deleteAll(): void {
    if (!$this->isStagedStorageReady()) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
    $entities = $storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->execute());
    if ($entities) {
      $storage->delete($entities);
    }
  }

  /**
   * Runs $callable inside the auto-save workspace when it exists.
   */
  public function executeInAutoSaveWorkspace(callable $callable): mixed {
    if ($this->workspaceManager === NULL
      || !$this->isWorkspaceStorageReady()
      || $this->entityTypeManager->getStorage('workspace')->load(AutoSaveWorkspace::ID) === NULL) {
      return $callable();
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    if ($wm->hasActiveWorkspace() && $wm->getActiveWorkspace()?->id() === AutoSaveWorkspace::ID) {
      return $callable();
    }
    return $wm->executeInWorkspace(AutoSaveWorkspace::ID, $callable);
  }

}
