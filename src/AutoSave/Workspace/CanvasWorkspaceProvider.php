<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\Provider\WorkspaceProviderBase;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use Drupal\workspaces\WorkspaceTrackerInterface;

/**
 * Legacy workspace provider for the pre-Phase-2 Canvas workspace.
 *
 * Phase 1 registered `canvas_default` under this provider to keep it out of
 * core listings and lock down its access. Phase 2 makes that workspace the
 * ordinary, visible "Main workspace" on core's `default` provider (see the
 * update path); this class remains only so a site mid-update — with the
 * workspace row still referencing the `canvas` provider — keeps resolving
 * access sanely until the post-update flips the provider.
 *
 * Access follows core workspace permissions (the parent implementation),
 * with two transitional carve-outs: update.php runs may view (switch into)
 * the workspace regardless of permissions, and authenticated users keep the
 * Phase 1 view grant so their staged drafts stay reachable until the update
 * path grants the corresponding core permissions.
 *
 * @see canvas_post_update_0024_main_workspace()
 * @see \Drupal\workspaces\WorkspaceAccessControlHandler
 */
final class CanvasWorkspaceProvider extends WorkspaceProviderBase {

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    WorkspaceManagerInterface $workspaceManager,
    WorkspaceTrackerInterface $workspaceTracker,
    WorkspaceInformationInterface $workspaceInfo,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($entityTypeManager, $workspaceManager, $workspaceTracker, $workspaceInfo);
  }

  /**
   * {@inheritdoc}
   */
  public static function getId(): string {
    return 'canvas';
  }

  /**
   * {@inheritdoc}
   */
  public function checkAccess(WorkspaceInterface $workspace, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view') {
      // update.php runs the legacy key-value migration as the anonymous user
      // (update_free_access) or as an operator without Canvas permissions;
      // switching into the workspace requires view access. update.php
      // requests are dispatched through UpdateKernel, which stubs every
      // request with the system.db_update route and does not define
      // MAINTENANCE_MODE, so the route name is the reliable signal. Core
      // already exempts CLI (drush updb) from the check.
      // @see canvas_post_update_0023_migrate_auto_save_to_workspace()
      // @see \Drupal\workspaces\WorkspaceManager::doSwitchWorkspace()
      // @see \Drupal\Core\Update\UpdateKernel::setupRequestMatch()
      if ($this->routeMatch->getRouteName() === 'system.db_update'
        || (\defined('MAINTENANCE_MODE') && \constant('MAINTENANCE_MODE') === 'update')) {
        return AccessResult::allowed()->setCacheMaxAge(0);
      }
      // Transitional Phase 1 grant, until the update path maps it onto core
      // workspace permissions.
      if ($account->isAuthenticated()) {
        return AccessResult::allowed()->addCacheContexts(['user.roles:authenticated']);
      }
    }
    return parent::checkAccess($workspace, $operation, $account);
  }

}
