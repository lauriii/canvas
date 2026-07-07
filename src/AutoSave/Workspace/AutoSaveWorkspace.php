<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

/**
 * Identifies the shared workspace used to stage Canvas auto-saves.
 *
 * The ID is `canvas_default` (not `canvas_auto_save`) on purpose: later
 * phases retain this exact workspace as the default Canvas workspace once
 * Canvas editing is fully workspace-scoped, and renaming a workspace after
 * sites hold staged data would require migrating its tracked associations.
 */
final class AutoSaveWorkspace {

  public const string ID = 'canvas_default';

  public const string LABEL = 'Canvas';

}
