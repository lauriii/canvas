<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Persists staged content entities in the auto-save workspace (immediate).
 *
 * When the storage layer cannot persist the draft as a workspace revision
 * (entity types Workspaces refuses to stage, or values the SQL layer
 * rejects), the draft falls back to a payload snapshot row so no auto-save is
 * ever dropped. Reads resolve snapshots before workspace revisions, and a
 * later successful revision persist deletes the snapshot again.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave::loadAutoSaveEntity()
 */
final class WorkspaceContentEntityPersist {

  public function __construct(
    private readonly AutoSaveSnapshotRepository $snapshotRepository,
    private readonly AutoSaveRevisionPruner $revisionPruner,
    private readonly AccountProxyInterface $currentUser,
    #[Autowire(service: 'logger.channel.canvas')]
    private readonly LoggerInterface $logger,
  ) {}

  public function persist(ContentEntityInterface $entity, ?string $clientId): void {
    // Never mutate the caller's entity object: saving inside the auto-save
    // workspace marks the object as a non-default pending revision, which
    // would leak into the caller's later saves of the same object (e.g. an
    // editor-initiated Live save silently becoming a pending revision).
    $to_save = clone $entity;
    $type_id = $entity->getEntityTypeId();
    $entity_id = (string) $entity->id();
    $langcode = WorkspaceAutoSave::snapshotLangcode($entity);
    try {
      $to_save->save();
      $this->revisionPruner->recordAndPrune($to_save);
      // The revision is now the current staged state; a snapshot row from an
      // earlier failed persist would otherwise shadow it forever.
      $this->snapshotRepository->deleteFor($type_id, $entity_id, $langcode);
    }
    catch (\Throwable $e) {
      // Retention over failure: keep the draft as a payload snapshot. If the
      // snapshot write fails too, the client must see the error.
      $this->logger->warning('Canvas auto-save for @type @id could not be stored as a workspace revision (@message); stored as a snapshot instead.', [
        '@type' => $type_id,
        '@id' => $entity_id,
        '@message' => $e->getMessage(),
      ]);
      $payload = \json_encode(AutoSaveManager::toStorableArray($entity), JSON_THROW_ON_ERROR);
      $data_hash = AutoSaveManager::generateHashFromData(AutoSaveManager::normalizeEntity($entity));
      $this->snapshotRepository->persist(
        $type_id,
        $entity_id,
        $langcode,
        $payload,
        $data_hash,
        $clientId,
        (int) $this->currentUser->id(),
      );
    }
  }

}
