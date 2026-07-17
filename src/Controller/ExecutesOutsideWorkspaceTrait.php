<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Runs callables outside the auto-save workspace.
 *
 * The auto-save workspace is active during Canvas API requests, so reads and
 * writes that must target Live — publishes, listings, deletes, duplicates —
 * have to explicitly step outside it. The using class must inject the
 * `workspaces.manager` service into a nullable `$workspaceManager` property.
 *
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspaceActivationSubscriber
 */
trait ExecutesOutsideWorkspaceTrait {

  /**
   * Runs $callable outside the auto-save workspace.
   *
   * @param callable $callable
   *   The callable to run in the Live workspace context.
   *
   * @return mixed
   *   The callable's return value.
   */
  private function executeOutsideWorkspace(callable $callable): mixed {
    return $this->workspaceManager instanceof WorkspaceManagerInterface
      ? $this->workspaceManager->executeOutsideWorkspace($callable)
      : $callable();
  }

}
