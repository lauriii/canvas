<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

/**
 * Identifies the default (Main) workspace backing Canvas auto-saves.
 *
 * The ID is `canvas_default` (not `canvas_auto_save`) on purpose: Phase 2
 * retains this exact workspace as the visible "Main workspace" now that
 * Canvas editing is fully workspace-scoped, and renaming a workspace after
 * sites hold staged data would require migrating its tracked associations.
 *
 * Canvas staging targets the active workspace; this workspace is the
 * fallback for editing sessions that have not selected a named workspace.
 */
final class AutoSaveWorkspace {

  public const string ID = 'canvas_default';

  public const string LABEL = 'Main workspace';

}
