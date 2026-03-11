<?php

declare(strict_types=1);

namespace Drupal\canvas\Access;

use Drupal\canvas\Entity\BrandKit;
use Drupal\canvas\Entity\JavaScriptComponent;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Checks access to the generic artifact upload endpoint.
 *
 * @internal
 */
final class ArtifactUploadAccessCheck implements AccessInterface {

  public function access(AccountInterface $account): AccessResult {
    $brand_kit_access = AccessResult::allowedIfHasPermission($account, BrandKit::ADMIN_PERMISSION);
    $code_component_access = AccessResult::allowedIfHasPermission($account, JavaScriptComponent::ADMIN_PERMISSION);

    if ($brand_kit_access->isAllowed() || $code_component_access->isAllowed()) {
      return $brand_kit_access->orIf($code_component_access);
    }

    return AccessResult::forbidden("Either the 'administer brand kit' permission or the 'administer code components' permission is required.")
      ->cachePerPermissions();
  }

}
