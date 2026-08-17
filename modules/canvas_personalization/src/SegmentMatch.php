<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The result of evaluating one segment against the current request.
 *
 * @see \Drupal\canvas_personalization\SegmentEvaluator
 */
final class SegmentMatch {

  /**
   * @param bool $matched
   *   Whether the visitor matches the segment.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the result varies by: the union of the segment's conditions' cache
   *   contexts, their minimum max-age, and the segment's config cache tag.
   */
  public function __construct(
    public readonly bool $matched,
    public readonly CacheableMetadata $cacheability,
  ) {}

}
