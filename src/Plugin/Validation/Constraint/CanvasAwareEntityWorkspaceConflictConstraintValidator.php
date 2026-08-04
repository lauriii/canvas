<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\workspaces\Plugin\Validation\Constraint\EntityWorkspaceConflictConstraint;
use Drupal\workspaces\Plugin\Validation\Constraint\EntityWorkspaceConflictConstraintValidator;
use Symfony\Component\Validator\Constraint;

/**
 * Validates EntityWorkspaceConflict with a narrow Main-workspace exemption.
 *
 * Core semantics apply to every named workspace: an entity with pending work
 * in workspace A cannot be edited in workspace B (or in Live). The one
 * exemption is Live editing of an entity tracked only in the Main workspace:
 * the Main workspace stages continuous Canvas drafts, and entities carrying
 * one must remain editable in Live (entity forms, JSON:API, scripts) —
 * Canvas detects the resulting divergence with its own conflict detection.
 * Editing that entity in another workspace stays blocked.
 *
 * @see \Drupal\canvas\AutoSave\AutoSaveManager::getUnresolvedConflict()
 */
final class CanvasAwareEntityWorkspaceConflictConstraintValidator extends EntityWorkspaceConflictConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $entity, Constraint $constraint): void {
    \assert($constraint instanceof EntityWorkspaceConflictConstraint);
    // Workspaces only tracks revisionable entities; anything else can never
    // conflict.
    // @see \Drupal\workspaces\WorkspaceTrackerInterface::getEntityTrackingWorkspaceIds()
    if (!$entity instanceof RevisionableInterface || $entity->isNew()) {
      return;
    }

    $tracking_workspace_ids = $this->workspaceTracker->getEntityTrackingWorkspaceIds($entity, TRUE);
    $active_workspace = $this->workspaceManager->getActiveWorkspace();

    // The exemption: a Live save (no active workspace) of an entity tracked
    // only in the Main workspace is allowed.
    if (!$active_workspace) {
      $tracking_workspace_ids = \array_diff($tracking_workspace_ids, [AutoSaveWorkspace::ID]);
    }
    if ($tracking_workspace_ids === []) {
      return;
    }

    // Mirrors the parent implementation for every other case.
    if (!$active_workspace || !\in_array($active_workspace->id(), $tracking_workspace_ids, TRUE)) {
      $first_tracking_workspace_id = \reset($tracking_workspace_ids);
      $workspace = $this->entityTypeManager->getStorage('workspace')
        ->load($first_tracking_workspace_id);
      \assert($workspace !== NULL);

      $this->context->buildViolation($constraint->message)
        ->setParameter('@label', (string) $workspace->label())
        ->addViolation();
    }
  }

}
