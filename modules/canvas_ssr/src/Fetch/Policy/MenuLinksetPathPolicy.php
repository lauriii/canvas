<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Policy;

/**
 * Allows same-site fetches of /system/menu/{menu}/linkset.
 */
final readonly class MenuLinksetPathPolicy implements PathPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function isAllowed(string $path): bool {
    return \preg_match('@^/system/menu/[A-Za-z0-9_-]+/linkset$@', $path) === 1;
  }

}
