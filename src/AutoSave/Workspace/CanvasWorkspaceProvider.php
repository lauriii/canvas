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
 * Workspace provider for the Canvas-managed workspace.
 *
 * Registering `canvas_default` under this provider (instead of core's
 * `default` provider for user-created workspaces) makes core treat it as
 * module-managed: the Workspaces UI listing filters it out, and all access
 * decisions delegate here.
 *
 * The workspace is internal infrastructure in this phase: Canvas editors may
 * only view it (required to activate it during Canvas API requests); editing
 * and deleting stay locked to workspace administrators. Publishing is denied
 * for every account, including workspace administrators, because core's
 * workspace-level publish pushes all staged revisions live without entity
 * validation, bypassing Canvas's selective, per-item publish validation.
 * Programmatic publishing is stopped as well.
 *
 * @see \Drupal\canvas\EventSubscriber\AutoSave\AutoSaveWorkspacePublishSubscriber
 *
 * @see \Drupal\workspaces\WorkspaceAccessControlHandler
 * @see \Drupal\workspaces\WorkspaceListBuilder::getEntityIds()
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
    if ($operation === 'publish') {
      // Core's workspace-level publish would push every staged auto-save live
      // without validating any entity. Canvas publishes selectively, per
      // item, with validation and access checks, so this operation is denied
      // for everyone. AutoSaveWorkspacePublishSubscriber additionally stops
      // programmatic Workspace::publish() calls, which do not check access.
      return AccessResult::forbidden('The Canvas workspace can only be published through the Canvas publish endpoint, which validates and access checks each item.')->cachePerPermissions();
    }
    if ($account->hasPermission('administer workspaces')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
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
      // Any authenticated user may view (i.e. switch into) the workspace:
      // Canvas auto-save reads happen on behalf of whoever may edit some
      // Canvas-enabled entity, which cannot be expressed as a fixed permission
      // list (e.g. a user with only a node edit permission). Viewing grants no
      // content access by itself; entity access still applies inside the
      // workspace, and edit, delete and publish stay locked down.
      if ($account->isAuthenticated()) {
        return AccessResult::allowed()->addCacheContexts(['user.roles:authenticated']);
      }
    }
    return AccessResult::forbidden('The Canvas workspace is internal infrastructure managed by the canvas module.')->cachePerPermissions();
  }

}
