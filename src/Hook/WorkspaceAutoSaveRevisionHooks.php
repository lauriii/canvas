<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave;
use Drupal\canvas\Workspace\WorkspaceReview;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\workspaces\Hook\EntityOperations;
use Drupal\workspaces\WorkspaceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reacts to entity saves that stage work into the active workspace.
 *
 * Keeps revision metadata accurate for staged content entity saves, and
 * demotes the workspace's review state on staged writes: an approval covers
 * a specific content state, not future edits.
 */
final class WorkspaceAutoSaveRevisionHooks {

  public function __construct(
    private readonly WorkspaceAutoSave $workspaceAutoSave,
    private readonly WorkspaceReview $workspaceReview,
    /**
     * @var \Drupal\workspaces\WorkspaceManagerInterface|null
     */
    #[Autowire(service: 'workspaces.manager')]
    private readonly ?object $workspaceManager = NULL,
    /**
     * @var \Drupal\workspaces\WorkspaceInformationInterface|null
     */
    #[Autowire(service: 'workspaces.information')]
    private readonly ?object $workspaceInformation = NULL,
  ) {}

  /**
   * Runs after workspaces sets ::setNewRevision(TRUE) on pending saves.
   */
  #[Hook('entity_presave', order: new OrderAfter(classesAndMethods: [[EntityOperations::class, 'entityPresave']]))]
  public function stampRevisionMetadataForAutoSaveWorkspace(EntityInterface $entity): void {
    $this->workspaceAutoSave->stampAutoSaveWorkspaceRevisionMetadata($entity);
    $this->demoteReviewStateOnStagedWrite($entity);
  }

  /**
   * Demotes the active workspace to draft when this save stages work in it.
   *
   * Covers non-Canvas writes too (node forms, workspace_config rows for
   * config edits): anything core tracks in the workspace is a staged write.
   * Canvas's own snapshot/buffer staging demotes via AutoSaveManager.
   *
   * @see \Drupal\canvas\AutoSave\AutoSaveManager::saveEntity()
   * @see \Drupal\canvas\Workspace\WorkspaceReview::demoteOnStagedWrite()
   */
  private function demoteReviewStateOnStagedWrite(EntityInterface $entity): void {
    if ($this->workspaceManager === NULL || $this->workspaceInformation === NULL) {
      return;
    }
    if ($entity instanceof WorkspaceInterface) {
      return;
    }
    if (!$entity instanceof ContentEntityInterface || $entity->isSyncing()) {
      return;
    }
    if (!$this->workspaceManager->hasActiveWorkspace()) {
      return;
    }
    // Only writes the workspace actually captures demote it: supported
    // entity types are tracked as pending revisions, and workspace_config
    // rows carry config staged by the workspace_config module.
    if (!$this->workspaceInformation->isEntitySupported($entity)
      && $entity->getEntityTypeId() !== 'workspace_config') {
      return;
    }
    /** @var \Drupal\workspaces\WorkspaceInterface $active */
    $active = $this->workspaceManager->getActiveWorkspace();
    // The workspace entity may not carry Canvas's base fields yet (update
    // path mid-flight).
    if (!$active->hasField('canvas_workspace_status')) {
      return;
    }
    $this->workspaceReview->demoteOnStagedWrite($active);
  }

}
