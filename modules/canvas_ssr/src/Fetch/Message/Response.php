<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Message;

use Drupal\Core\Cache\CacheableResponseTrait;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;

/**
 * A Guzzle PSR-7 response with a URL and cacheability.
 *
 * The URL is set with withUri(), so Guzzle's constructor stays as it is.
 * Guzzle's other withers clone, so they keep it.
 */
final class Response extends GuzzleResponse implements ResponseInterface {

  use CacheableResponseTrait;

  /**
   * The URL requested, if set.
   */
  private ?UriInterface $uri = NULL;

  /**
   * Whether its cacheability isn't known.
   */
  private bool $cacheabilityUnknown = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getUri(): UriInterface {
    // Empty if unset, like the fetch API's Response.url.
    return $this->uri ?? new Uri('');
  }

  /**
   * {@inheritdoc}
   */
  public function withUri(UriInterface $uri): static {
    $new = clone $this;
    $new->uri = $uri;

    return $new;
  }

  /**
   * {@inheritdoc}
   */
  public function hasCacheability(): bool {
    return !$this->cacheabilityUnknown;
  }

  /**
   * Returns a response whose cacheability isn't known.
   *
   * Only a fetch makes one, so it isn't on the interface, which is what
   * readers of a response use.
   *
   * @return static
   *   A copy that says so.
   */
  public function withoutCacheability(): static {
    $new = clone $this;
    $new->cacheabilityUnknown = TRUE;

    return $new;
  }

}
