<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Retains roughly 2 log2(n) auto-save revisions using log-spaced snapshots.
 *
 * @see https://madebyevan.com/algos/log-spaced-snapshots/
 */
final class AutoSaveRevisionPruner {

  public const string STORE = 'canvas.auto_save_snapshots';

  public const int DEFAULT_DENSITY = 2;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
    #[Autowire(service: 'keyvalue')]
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  public function recordAndPrune(ContentEntityInterface $entity, int $density = self::DEFAULT_DENSITY): void {
    if ($this->workspaceManager === NULL || $this->workspaceAssociation === NULL) {
      return;
    }
    if ($entity->id() === NULL) {
      return;
    }
    $type = $entity->getEntityTypeId();
    $id = (string) $entity->id();
    $key = $type . ':' . $id;
    $store = $this->keyValueFactory->get(self::STORE);

    /** @var \Drupal\workspaces\WorkspaceTrackerInterface $association */
    $association = $this->workspaceAssociation;
    $tracked = $association->getTrackedEntities(AutoSaveWorkspace::ID, $type, [$id]);
    $tracked_ids = [];
    if (!empty($tracked[$type])) {
      foreach (\array_keys($tracked[$type]) as $revision_id) {
        $tracked_ids[] = (int) $revision_id;
      }
    }
    if ($tracked_ids === []) {
      return;
    }

    $state = $store->get($key);
    if (!\is_array($state) || !isset($state['next_step'], $state['steps']) || !\is_array($state['steps'])) {
      $state = [
        'next_step' => 1,
        'steps' => [],
      ];
    }
    $state['steps'] = array_filter(
      $state['steps'],
      static fn ($revision_id): bool => \in_array((int) $revision_id, $tracked_ids, TRUE),
    );
    if ($state['steps'] === []) {
      $state['next_step'] = 1;
    }

    $current_revision = (int) $entity->getRevisionId();
    $n = (int) $state['next_step'];
    $state['steps'][$n] = $current_revision;

    $delete_step = $n - (self::firstZeroBit($n) << $density);
    if ($delete_step > 0 && isset($state['steps'][$delete_step])) {
      $rev = (int) $state['steps'][$delete_step];
      if ($rev !== $current_revision && \in_array($rev, $tracked_ids, TRUE)) {
        $this->deleteRevisionInWorkspace($type, $rev);
      }
      unset($state['steps'][$delete_step]);
    }

    $state['next_step'] = $n + 1;
    $store->set($key, $state);
  }

  public function reset(EntityInterface $entity): void {
    if ($entity->id() === NULL) {
      return;
    }
    $this->keyValueFactory->get(self::STORE)->delete($entity->getEntityTypeId() . ':' . (string) $entity->id());
  }

  /**
   * Returns the least significant zero bit (Evan Wallace's firstZeroBit).
   */
  public static function firstZeroBit(int $x): int {
    return ($x + 1) & ~$x;
  }

  private function deleteRevisionInWorkspace(string $type, int $revision_id): void {
    /** @var \Drupal\workspaces\WorkspaceManagerInterface $wm */
    $wm = $this->workspaceManager;
    $wm->executeInWorkspace(AutoSaveWorkspace::ID, function () use ($type, $revision_id): void {
      $storage = $this->entityTypeManager->getStorage($type);
      if ($storage instanceof RevisionableStorageInterface) {
        $storage->deleteRevision($revision_id);
      }
    });
  }

}
