<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\Access;

use Drupal\canvas_headless\Grant\PreviewAssertionGrant;
use Drupal\Core\Session\AccessPolicyBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\CalculatedPermissionsItem;
use Drupal\Core\Session\RefinableCalculatedPermissionsInterface;
use Drupal\simple_oauth\Authentication\TokenAuthUserInterface;
use Drupal\simple_oauth\Oauth2ScopeProviderInterface;
use Drupal\simple_oauth\Plugin\Field\FieldType\Oauth2ScopeReferenceItemListInterface;

/**
 * Clamps administrative roles down to the preview scope's view-only ceiling.
 *
 * Simple OAuth's access policy intersects a preview token's permissions with
 * the scope's view-only ceiling, which caps an ordinary editor correctly. It
 * cannot cap an administrative role, though: such a role carries the isAdmin
 * flag instead of an enumerated permission list, and core's permission check
 * short-circuits to TRUE whenever any calculated item is admin. Left alone,
 * an editor holding an administrative role (or the super user) would receive
 * a preview token that authenticates with every permission — the exact
 * write access the view-only ceiling exists to withhold.
 *
 * For a token carrying the preview scope, this policy replaces each admin
 * item with the scope's ceiling permissions and clears the flag, so the
 * editor previews with exactly the view-only permissions and no more. The
 * rewrite is order-independent: whether it runs before or after Simple
 * OAuth's intersection, the intersection copies the (cleared) flag and
 * re-intersects the ceiling against itself, leaving the same result.
 *
 * @see \Drupal\simple_oauth\Access\Oauth2AccessPolicy
 * @see \Drupal\canvas_headless\Plugin\ScopeGranularity\PreviewSafePermissions
 */
final class PreviewCeilingAccessPolicy extends AccessPolicyBase {

  public function __construct(
    protected Oauth2ScopeProviderInterface $scopeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(string $scope): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function alterPermissions(AccountInterface $account, string $scope, RefinableCalculatedPermissionsInterface $calculated_permissions): void {
    if (!$account instanceof TokenAuthUserInterface) {
      return;
    }

    // Act only on tokens carrying the preview scope; every other token is
    // Simple OAuth's to govern.
    $scopes = $account->getToken()->get('scopes');
    \assert($scopes instanceof Oauth2ScopeReferenceItemListInterface);
    $preview_scope = NULL;
    foreach ($scopes->getScopes() as $oauth2_scope) {
      if ($oauth2_scope->getName() === PreviewAssertionGrant::SCOPE) {
        $preview_scope = $oauth2_scope;
        break;
      }
    }
    if ($preview_scope === NULL) {
      return;
    }

    $ceiling = $this->scopeProvider->getPermissions($preview_scope);
    foreach ($calculated_permissions->getItems() as $item) {
      if (!$item->isAdmin()) {
        continue;
      }
      $calculated_permissions->addItem(
        new CalculatedPermissionsItem(
          permissions: $ceiling,
          isAdmin: FALSE,
          scope: $item->getScope(),
          identifier: $item->getIdentifier(),
        ),
        overwrite: TRUE,
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistentCacheContexts(): array {
    return ['oauth2_scopes'];
  }

}
