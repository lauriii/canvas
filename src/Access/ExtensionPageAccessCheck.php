<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\Extension\CanvasExtensionPluginManager;
use Drupal\canvas\Extension\CanvasExtensionTypeEnum;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access to full-page Canvas extensions.
 *
 * A page extension is only accessible when the account has every permission the
 * extension declares in its `*.canvas_extension.yml` file. When an extension
 * declares no permissions, access falls back to the route's `_canvas_ui_access`
 * requirement (i.e. any user who can edit in Canvas), matching the base gate
 * used by the modal/sidebar extensions.
 *
 * @see \Drupal\canvas\Access\CanvasUiAccessCheck
 *
 * @internal
 */
final class ExtensionPageAccessCheck implements AccessInterface {

  public function __construct(
    private readonly CanvasExtensionPluginManager $extensionPluginManager,
  ) {}

  public function access(string $extension_id, AccountInterface $account): AccessResultInterface {
    $extensions = $this->extensionPluginManager->getDefinitions();
    if (!isset($extensions[$extension_id]) || $extensions[$extension_id]->getType() !== CanvasExtensionTypeEnum::Page) {
      return AccessResult::forbidden(\sprintf("There is no page extension with the ID '%s'.", $extension_id))
        ->addCacheableDependency($this->extensionPluginManager);
    }
    // Seed with allowed: a page extension that declares no permissions is
    // intentionally available to anyone who can edit in Canvas, since the route
    // already enforces `_canvas_ui_access`. Each declared permission is then
    // required on top of that base gate.
    $access = AccessResult::allowed();
    foreach ($extensions[$extension_id]->getPermissions() as $permission) {
      $access = $access->andIf(AccessResult::allowedIfHasPermission($account, $permission));
    }
    \assert($access instanceof AccessResult);
    return $access->addCacheableDependency($this->extensionPluginManager);
  }

}
