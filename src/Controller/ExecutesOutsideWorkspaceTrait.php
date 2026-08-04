<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Runs callables outside the auto-save workspace.
 *
 * A workspace may be active during Canvas API requests (core negotiation),
 * so the few writes that must target Live — content deletion, translation
 * removal — have to explicitly step outside it. The using class must inject
 * the `workspaces.manager` service into a nullable `$workspaceManager`
 * property.
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
