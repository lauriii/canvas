<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\workspaces\Plugin\Validation\Constraint\EntityWorkspaceConflictConstraintValidator;
use Symfony\Component\Validator\Constraint;

/**
 * Validates EntityWorkspaceConflict, ignoring the Canvas auto-save workspace.
 *
 * Core forbids editing an entity outside the workspace that tracks it. The
 * Canvas auto-save workspace stages drafts, not exclusive edits: entities
 * with a Canvas draft must remain editable in Live (entity forms, JSON:API,
 * scripts), and Canvas detects the resulting divergence with its own conflict
 * detection instead.
 *
 * @see \Drupal\canvas\AutoSave\AutoSaveManager::getUnresolvedConflict()
 */
final class CanvasAwareEntityWorkspaceConflictConstraintValidator extends EntityWorkspaceConflictConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint): void {
    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    if (!isset($entity) || $entity->isNew()) {
      return;
    }

    $tracking_workspace_ids = \array_diff(
      $this->workspaceTracker->getEntityTrackingWorkspaceIds($entity, TRUE),
      [AutoSaveWorkspace::ID],
    );
    if ($tracking_workspace_ids === []) {
      return;
    }

    // Mirrors the parent implementation for every other workspace.
    $active_workspace = $this->workspaceManager->getActiveWorkspace();
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
