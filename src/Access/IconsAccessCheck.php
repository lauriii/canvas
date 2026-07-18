<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\Entity\IconLibrary;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access to the icon pack listing.
 *
 * Editors reach it through the Canvas UI (icon picker, Brand Kit); the Canvas
 * CLI reaches it with the icon library management permission when pulling
 * icon libraries.
 *
 * @internal
 */
class IconsAccessCheck implements AccessInterface {

  public function __construct(
    private readonly CanvasUiAccessCheck $canvasUiAccessCheck,
  ) {}

  public function access(AccountInterface $account): AccessResult {
    $admin_access = AccessResult::allowedIfHasPermission($account, IconLibrary::ADMIN_PERMISSION);
    if ($admin_access->isAllowed()) {
      return $admin_access;
    }
    return $admin_access->orIf($this->canvasUiAccessCheck->access($account));
  }

}
