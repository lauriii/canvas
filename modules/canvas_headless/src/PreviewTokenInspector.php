<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Oauth2ScopeInterface;
use Drupal\simple_oauth\Plugin\Field\FieldType\Oauth2ScopeReferenceItemListInterface;

/**
 * Identifies user-bound OAuth tokens carrying the Canvas preview scope.
 */
final class PreviewTokenInspector {

  /**
   * Determines whether an account's token carries the Canvas preview scope.
   */
  public static function hasPreviewScope(AccountInterface $account): bool {
    return self::getPreviewScope($account) !== NULL;
  }

  /**
   * Gets the Canvas preview scope carried by an account's token.
   */
  public static function getPreviewScope(AccountInterface $account): ?Oauth2ScopeInterface {
    if (!$account instanceof TokenAuthUserInterface) {
      return NULL;
    }

    $scopes = $account->getToken()->get('scopes');
    if (!$scopes instanceof Oauth2ScopeReferenceItemListInterface) {
      return NULL;
    }

    foreach ($scopes->getScopes() as $oauth2_scope) {
      if ($oauth2_scope->getName() === PreviewAssertionGrant::SCOPE) {
        return $oauth2_scope;
      }
    }
    return NULL;
  }

}
