<?php

declare(strict_types=1);

namespace Drupal\canvas\ListBuilder;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The result of one List element query window.
 *
 * @see \Drupal\canvas\ListBuilder\ListQueryExecutor
 *
 * @internal
 */
final class ListQueryResult {

  /**
   * @param array<int|string, EntityInterface> $entities
   *   The loaded result entities for the requested window, in query order and
   *   in the current content language where available.
   * @param bool $hasMore
   *   Whether more results exist beyond this window.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of the query itself (list cache tag, language and
   *   permissions contexts). Item render cacheability is not included.
   */
  public function __construct(
    public readonly array $entities,
    public readonly bool $hasMore,
    public readonly CacheableMetadata $cacheability,
  ) {}

}
