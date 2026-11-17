<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Fetch;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The cacheability of what a render fetched, and whether all of it is known.
 *
 * @see \Drupal\canvas_ssr\Fetch\Message\ResponseInterface::hasCacheability()
 * @see \Drupal\canvas_ssr\Renderer
 */
final class FetchedCacheability extends CacheableMetadata {

  /**
   * Whether a response the render fetched had no known cacheability.
   *
   * Then nothing made from it may be cached as it is, so the island falls
   * back to CSR.
   */
  public bool $unknown = FALSE;

  /**
   * Why a fetch the render made failed, if one did.
   *
   * SSR refuses fetches the browser makes, so the browser's may not have
   * failed. The island falls back to CSR, however the component handled it.
   */
  public ?string $failure = NULL;

  /**
   * Whether that fetch fails the same way every time, as a refused one does.
   */
  public bool $failureRecurs = FALSE;

  /**
   * The machine name of the root component of the island that made it.
   *
   * A later island that takes the failure on isn't the one to look in.
   */
  public ?string $failedIn = NULL;

  /**
   * Takes on what an earlier render fetched.
   *
   * @param self $earlier
   *   What it fetched: its cacheability, and whether any was unknown or a
   *   fetch failed.
   *
   * @return $this
   */
  public function inherit(self $earlier): static {
    $this->addCacheableDependency($earlier);
    $this->unknown = $this->unknown || $earlier->unknown;
    if ($this->failure === NULL && $earlier->failure !== NULL) {
      $this->failure = $earlier->failure;
      $this->failureRecurs = $earlier->failureRecurs;
      $this->failedIn = $earlier->failedIn;
    }

    return $this;
  }

}
