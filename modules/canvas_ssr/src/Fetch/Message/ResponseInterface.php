<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch\Message;

use Drupal\Core\Cache\CacheableResponseInterface;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 response that also has a URL and cacheability.
 *
 * The URL becomes Response.url in JS. The cacheability joins the render's,
 * unless it isn't known.
 *
 * @see \Drupal\canvas_ssr\Fetch\FetchInterface::fetch()
 */
interface ResponseInterface extends PsrResponseInterface, CacheableResponseInterface {

  /**
   * Returns the URL requested.
   *
   * @return \Psr\Http\Message\UriInterface
   *   The URL, resolved against the current request. Empty if unset, like
   *   the fetch API's Response.url.
   */
  public function getUri(): UriInterface;

  /**
   * Returns a response with the given URL.
   *
   * @param \Psr\Http\Message\UriInterface $uri
   *   The URL requested.
   *
   * @return static
   *   A copy with that URL.
   */
  public function withUri(UriInterface $uri): static;

  /**
   * Returns whether its cacheability is known.
   *
   * An answer that wasn't cacheable, or came from a route that mustn't be
   * cached, says nothing of how long markup made from it holds.
   *
   * @return bool
   *   FALSE when its cacheability isn't known, so nothing made from it may
   *   be cached as it is.
   */
  public function hasCacheability(): bool;

}
