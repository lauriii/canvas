<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * An island's server-rendered markup, with its cacheability.
 *
 * The markup bakes in the component graph, site and page data and fetched
 * data, so the island has to vary with them. Like GeneratedLink, the caller
 * applies the cacheability.
 *
 * With no markup, the island falls back to CSR. Its cacheability then says
 * how long that holds: as long as what the failure depended on, or briefly
 * when it may not recur.
 *
 * @see \Drupal\Core\GeneratedLink
 * @see \Drupal\canvas_ssr\Renderer::render()
 */
final class RenderResult implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  /**
   * Constructs a RenderResult.
   *
   * @param string|null $html
   *   The markup, or NULL to fall back to CSR.
   * @param bool $hydrates
   *   Whether the markup hydrates in the browser.
   * @param string|null $hydrationData
   *   What the island's libraries give its first render in the browser, by
   *   library, as JSON. NULL for a static island, or one whose libraries
   *   provided none.
   */
  public function __construct(
    public readonly ?string $html,
    public readonly bool $hydrates,
    public readonly ?string $hydrationData = NULL,
  ) {}

  /**
   * Creates a result that falls back to CSR.
   *
   * @return self
   *   A result with no markup, which the browser renders and hydrates.
   */
  public static function clientSide(): self {
    return new self(NULL, TRUE);
  }

}
