<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\workspaces\Hook\EntityOperations;

/**
 * Keeps revision metadata accurate for auto-save-staged content entity saves.
 */
final class WorkspaceAutoSaveRevisionHooks {

  public function __construct(
    private readonly WorkspaceAutoSave $workspaceAutoSave,
  ) {}

  /**
   * Runs after workspaces sets ::setNewRevision(TRUE) on pending saves.
   */
  #[Hook('entity_presave', order: new OrderAfter(classesAndMethods: [[EntityOperations::class, 'entityPresave']]))]
  public function stampRevisionMetadataForAutoSaveWorkspace(EntityInterface $entity): void {
    $this->workspaceAutoSave->stampAutoSaveWorkspaceRevisionMetadata($entity);
  }

}
