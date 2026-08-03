<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\CanvasAutoSaveSnapshot;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads and writes payload snapshot rows, one per staged target per workspace.
 *
 * Snapshots are workspace-ignored (see CanvasAutoSaveSnapshot), so reads and
 * writes here behave the same regardless of the caller's workspace context;
 * the rows themselves carry the workspace they were staged in, and every
 * read/write is scoped to one workspace (the active one by default).
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
   * The workspace snapshot operations default to: active, or Main.
   */
  private function defaultWorkspaceId(): string {
    return AutoSaveManager::activeWorkspaceId();
  }

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

  public function resolveLatestStaged(string $targetEntityTypeId, string $targetEntityId, string $targetLangcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, ?string $workspaceId = NULL): ?CanvasAutoSaveSnapshot {
    if (!$this->isStagedStorageReady()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspaceId ?? $this->defaultWorkspaceId())
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
   * Creates or updates the single snapshot row for a target in a workspace.
   *
   * The unique key over (workspace, type, id, langcode) makes concurrent
   * first saves race-safe: the loser of the insert race retries as an update.
   *
   * @see \Drupal\canvas\Entity\Storage\CanvasAutoSaveSnapshotStorageSchema
   */
  public function persist(string $targetEntityTypeId, string $targetEntityId, string $targetLangcode, string $payload, string $dataHash, ?string $clientId, int $ownerId, ?string $workspaceId = NULL): CanvasAutoSaveSnapshot {
    if (!$this->isStagedStorageReady()) {
      throw new \RuntimeException('The canvas_auto_save_snapshot entity schema must be installed (run database updates).');
    }
    $workspaceId ??= $this->defaultWorkspaceId();
    $existing = $this->resolveLatestStaged($targetEntityTypeId, $targetEntityId, $targetLangcode, $workspaceId);
    if ($existing === NULL) {
      $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
      $snapshot = $storage->create([
        'workspace' => $workspaceId,
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
        $existing = $this->resolveLatestStaged($targetEntityTypeId, $targetEntityId, $targetLangcode, $workspaceId);
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

  public function deleteFor(string $targetEntityTypeId, string $targetEntityId, string $targetLangcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, ?string $workspaceId = NULL): void {
    $snapshot = $this->resolveLatestStaged($targetEntityTypeId, $targetEntityId, $targetLangcode, $workspaceId);
    $snapshot?->delete();
  }

  /**
   * Loads every snapshot row staged in one workspace.
   *
   * @return \Drupal\canvas\Entity\CanvasAutoSaveSnapshot[]
   */
  public function loadAll(?string $workspaceId = NULL): array {
    if (!$this->isStagedStorageReady()) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspaceId ?? $this->defaultWorkspaceId());
    /** @var \Drupal\canvas\Entity\CanvasAutoSaveSnapshot[] */
    return $storage->loadMultiple($query->execute());
  }

  /**
   * Deletes the snapshot rows staged in one workspace.
   */
  public function deleteAll(?string $workspaceId = NULL): void {
    if (!$this->isStagedStorageReady()) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage(CanvasAutoSaveSnapshot::ENTITY_TYPE_ID);
    $entities = $storage->loadMultiple($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('workspace', $workspaceId ?? $this->defaultWorkspaceId())
      ->execute());
    if ($entities) {
      $storage->delete($entities);
    }
  }

  /**
   * Runs $callable inside the staging workspace when it exists.
   *
   * The staging workspace is the active workspace when one is negotiated
   * (in which case this is a passthrough) or the Main workspace otherwise.
   */
  public function executeInStagingWorkspace(callable $callable): mixed {
    if ($this->workspaceManager === NULL || !$this->isWorkspaceStorageReady()) {
      return $callable();
    }
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    if ($wm->hasActiveWorkspace()) {
      return $callable();
    }
    if ($this->entityTypeManager->getStorage('workspace')->load(AutoSaveWorkspace::ID) === NULL) {
      return $callable();
    }
    return $wm->executeInWorkspace(AutoSaveWorkspace::ID, $callable);
  }

}
