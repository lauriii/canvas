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
   * @param array<int|string, \Drupal\Core\Entity\EntityInterface> $entities
   *   The loaded result entities for the requested window, in query order and
   *   in the current content language where available.
   * @param bool $hasMore
   *   Whether more results exist beyond this window.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of the query itself (list cache tag, language and
   *   permissions contexts). Item render cacheability is not included.
   * @param int $consumed
   *   How many query rows this window consumed. Pagination offsets advance by
   *   this number, not by the count of $entities: if the render-time access
   *   guard drops an entity, the next window must still start after the
   *   consumed rows, or pages would overlap.
   */
  public function __construct(
    public readonly array $entities,
    public readonly bool $hasMore,
    public readonly CacheableMetadata $cacheability,
    public readonly int $consumed,
  ) {}

}
