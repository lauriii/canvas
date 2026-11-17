<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Policy;

/**
 * Allows same-site fetches of the site's JSON:API endpoints.
 *
 * Only paths under JSON:API's configured base path are allowed. If
 * jsonapi_extras changes it to "/api", say, "/jsonapi" isn't allowed.
 * CanvasSsrServiceProvider registers it only when jsonapi is enabled.
 *
 * @see \Drupal\canvas_ssr\CanvasSsrServiceProvider
 */
final readonly class JsonApiPathPolicy implements PathPolicyInterface {

  /**
   * Constructs a JsonApiPathPolicy.
   *
   * @param string $jsonApiBasePath
   *   The jsonapi.base_path container parameter, like "/jsonapi".
   */
  public function __construct(
    private string $jsonApiBasePath,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isAllowed(string $path): bool {
    $root = '/' . \ltrim($this->jsonApiBasePath, '/');
    return $path === $root || \str_starts_with($path, $root . '/');
  }

}
