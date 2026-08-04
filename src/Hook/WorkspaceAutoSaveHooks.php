<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Plugin\Validation\Constraint\CanvasAwareEntityChangedConstraint;
use Drupal\canvas\Plugin\Validation\Constraint\CanvasAwareEntityWorkspaceConflictConstraint;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\workspaces\Entity\Handler\IgnoredWorkspaceHandler;

/**
 * Workspace-related entity type and validation-constraint alterations.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\WorkspaceAutoSave
 */
final class WorkspaceAutoSaveHooks {

  /**
   * Implements hook_entity_type_build().
   */
  #[Hook('entity_type_build')]
  public static function entityTypeBuild(array &$entity_types): void {
    if (!\class_exists(IgnoredWorkspaceHandler::class)) {
      return;
    }
    // Canvas config entities (components, code components, asset libraries,
    // patterns, folders, staged config updates, personalization segments, …)
    // are staged by Canvas's own snapshot system, not by Workspaces. Without
    // this, core's workspace provider forbids saving them while the Canvas
    // auto-save workspace is active during Canvas API requests.
    // @see \Drupal\workspaces\Provider\WorkspaceProviderBase::entityPresave()
    foreach ($entity_types as $entity_type) {
      if (!$entity_type instanceof ConfigEntityTypeInterface || $entity_type->hasHandlerClass('workspace')) {
        continue;
      }
      $provider = $entity_type->getProvider();
      if ($provider === 'canvas' || \str_starts_with($provider, 'canvas_')) {
        $entity_type->setHandlerClass('workspace', IgnoredWorkspaceHandler::class);
      }
    }
  }

  /**
   * Implements hook_validation_constraint_alter().
   */
  #[Hook('validation_constraint_alter')]
  public static function validationConstraintAlter(array &$definitions): void {
    // Entities staged in the Canvas auto-save workspace must stay editable in
    // Live; Canvas has its own conflict detection for external edits. Swap in
    // a validator that ignores the Canvas workspace and otherwise behaves
    // exactly like core's.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasAwareEntityWorkspaceConflictConstraintValidator
    if (isset($definitions['EntityWorkspaceConflict'])) {
      $definitions['EntityWorkspaceConflict']['class'] = CanvasAwareEntityWorkspaceConflictConstraint::class;
    }
    // The changed timestamp of an entity's staged draft revision advances on
    // every auto-save flush, so with the Canvas auto-save workspace active,
    // core's workspace-aware loadUnchanged() makes concurrent Canvas preview
    // requests record false "modified by another user" conflicts against each
    // other. Compare against the Live entity instead.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\CanvasAwareEntityChangedConstraintValidator
    if (isset($definitions['EntityChanged'])) {
      $definitions['EntityChanged']['class'] = CanvasAwareEntityChangedConstraint::class;
    }
  }

}
