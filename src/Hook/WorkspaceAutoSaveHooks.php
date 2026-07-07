<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\AutoSave\Workspace\AutoSaveWorkspace;
use Drupal\Core\Hook\Attribute\Hook;

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
