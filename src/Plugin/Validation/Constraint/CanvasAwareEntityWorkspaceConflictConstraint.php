<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\workspaces\Plugin\Validation\Constraint\EntityWorkspaceConflictConstraint;

/**
 * Canvas-aware replacement for core's EntityWorkspaceConflict constraint.
 *
 * Swapped in via hook_validation_constraint_alter() so that entities whose
 * only pending revisions live in the Canvas auto-save workspace stay editable
 * outside it.
 *
 * @see \Drupal\canvas\Hook\WorkspaceAutoSaveHooks::validationConstraintAlter()
 */
final class CanvasAwareEntityWorkspaceConflictConstraint extends EntityWorkspaceConflictConstraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return CanvasAwareEntityWorkspaceConflictConstraintValidator::class;
  }

}
