<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\CanvasServiceProvider;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Migrates legacy key-value auto-save entries into workspace staging.
 */
final class LegacyAutoSaveMigrator {

  public function __construct(
    // Staging bookkeeping must resolve identically in every workspace.
    // @see \Drupal\canvas\CanvasServiceProvider::registerWorkspaceInvariantKeyValueFactory()
    #[Autowire(service: CanvasServiceProvider::STAGING_KEY_VALUE_SERVICE)]
    private readonly KeyValueFactoryInterface $keyValueFactory,
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly Connection $database,
  ) {}

  public function migrateIfNeeded(EntityInterface $entity): void {
    $store = $this->keyValueFactory->get(AutoSaveManager::AUTO_SAVE_STORE);
    $key = AutoSaveManager::getAutoSaveKey($entity);
    $legacy = $store->get($key);
    if ($legacy === NULL) {
      return;
    }
    // The key-value entry IS this entity's staging (workspace infrastructure
    // missing, or an entity type that stages in key-value by design): there is
    // nothing to migrate into, and "migrating" would rewrite and then delete
    // the same key-value row, losing the draft.
    if ($this->workspaceAutoSave->usesKeyValueStaging($entity)) {
      return;
    }
    if ($this->workspaceAutoSave->hasWorkspaceStaging($entity)) {
      $store->delete($key);
      return;
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->workspaceAutoSave->importLegacyArray($entity, $legacy);
      $store->delete($key);
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
  }

}
