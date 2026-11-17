<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Policy;

/**
 * Decides whether the same-site fetch may request a path.
 *
 * SameSiteFetch checks the scheme and host, strips the base path and finds
 * the system path, then asks each policy in turn. Each policy covers one
 * kind of endpoint, so one that needs an optional Drupal module can be
 * registered only when that Drupal module is enabled.
 */
interface PathPolicyInterface {

  /**
   * Determines whether this policy allows a path.
   *
   * @param string $path
   *   The system path, without the site's base path or a language prefix,
   *   like "/jsonapi/node/article".
   *
   * @return bool
   *   TRUE if this policy allows the path.
   */
  public function isAllowed(string $path): bool;

}
