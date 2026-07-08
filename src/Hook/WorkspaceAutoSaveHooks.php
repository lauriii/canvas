<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\canvas\Plugin\Validation\Constraint\CanvasAwareEntityWorkspaceConflictConstraint;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\workspaces\Entity\Handler\IgnoredWorkspaceHandler;

/**
 * Keeps the Canvas workspace out of core Workspaces UI surfaces.
 *
 * Access control lives in CanvasWorkspaceProvider, and the Workspaces UI
 * listing (including the toolbar off-canvas list) already filters to core's
 * `default` provider. The switcher form is the one surface that builds its
 * options from the entity reference selection handler, which has no provider
 * condition, so it must be filtered here.
 *
 * @see \Drupal\canvas\AutoSave\Workspace\CanvasWorkspaceProvider
 * @see \Drupal\workspaces\Form\WorkspaceSwitcherForm::buildForm()
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
  }

  /**
   * Implements hook_form_FORM_ID_alter() for workspace_switcher_form.
   */
  #[Hook('form_workspace_switcher_form_alter')]
  public static function workspaceSwitcherFormAlter(array &$form): void {
    if (!isset($form['workspace_id']['#options'])) {
      return;
    }
    unset($form['workspace_id']['#options'][AutoSaveWorkspace::ID]);
    if (empty($form['workspace_id']['#options'])) {
      $form['workspace_id']['#access'] = FALSE;
      if (isset($form['actions']['submit'])) {
        $form['actions']['submit']['#access'] = FALSE;
      }
    }
  }

}
