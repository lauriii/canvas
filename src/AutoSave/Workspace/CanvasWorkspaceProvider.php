<?php

declare(strict_types=1);

namespace Drupal\canvas\AutoSave\Workspace;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\workspaces\Provider\WorkspaceProviderBase;
use Drupal\workspaces\WorkspaceInterface;

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
      // update.php runs the legacy key-value migration as the anonymous user;
      // switching into the workspace requires view access. Core already
      // exempts CLI (drush updb) from the check.
      // @see canvas_post_update_0023_migrate_auto_save_to_workspace()
      // @see \Drupal\workspaces\WorkspaceManager::doSwitchWorkspace()
      if (\defined('MAINTENANCE_MODE') && \constant('MAINTENANCE_MODE') === 'update') {
        return AccessResult::allowed()->setCacheMaxAge(0);
      }
      if (self::accountHasCanvasAutoSaveWorkspaceAccess($account)) {
        return AccessResult::allowed()->cachePerPermissions();
      }
    }
    return AccessResult::forbidden('The Canvas workspace is internal infrastructure managed by the canvas module.')->cachePerPermissions();
  }

  private static function accountHasCanvasAutoSaveWorkspaceAccess(AccountInterface $account): bool {
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    $permissions = [
      AutoSaveManager::PUBLISH_PERMISSION,
      'edit canvas_page',
      'create canvas_page',
      'administer components',
      'administer code components',
      'administer brand kit',
      'administer content templates',
    ];
    foreach ($permissions as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
