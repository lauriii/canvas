<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\EntityChangedConstraint;

/**
 * Canvas-aware replacement for core's EntityChanged constraint.
 *
 * Swapped in via hook_validation_constraint_alter() so that the changed
 * timestamp is compared against the Live entity even while the Canvas
 * auto-save workspace is active.
 *
 * @see \Drupal\canvas\Hook\WorkspaceAutoSaveHooks::validationConstraintAlter()
 */
final class CanvasAwareEntityChangedConstraint extends EntityChangedConstraint {

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return CanvasAwareEntityChangedConstraintValidator::class;
  }

}
