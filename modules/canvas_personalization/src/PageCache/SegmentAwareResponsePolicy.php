<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\PageCache;

use Drupal\canvas_personalization\SegmentEvaluator;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the URL-keyed internal page cache correct for personalized responses.
 *
 * Drupal's internal page cache keys entries on the URL alone and ignores
 * cache contexts entirely, and its entries expire only via the Expires header
 * (it ignores max-age). Two consequences for personalization:
 *
 * - A response whose negotiation can vary by anything not derivable from the
 *   URL (a geolocation header, a third-party provider cookie) would leak the
 *   first visitor's variant to every later visitor of the same URL. Such
 *   responses are DENYed here; they remain fully cacheable in
 *   dynamic_page_cache, which keys on cache contexts correctly.
 * - A response whose negotiation is uncacheable (max-age 0, e.g. a provider
 *   that opted out of caching) would be stored permanently, because nothing
 *   in core denies storage for max-age 0. DENY those too.
 *
 * The decision derives from the response's segment cache tags plus the
 * segments' configuration-declared condition cacheability — never from
 * whether evaluation ran on this request. That distinction is load-bearing:
 * when dynamic_page_cache serves the personalized response, no evaluation
 * runs, yet the response must still be kept out of the URL-keyed cache.
 *
 * URL-derived contexts (`url.query_args:*` from query-parameter and UTM
 * conditions) pass through: different query strings are different page_cache
 * entries, so those personalized pages get full page_cache hits per variant
 * URL. Responses without segment tags are never affected.
 *
 * @see \Drupal\page_cache\StackMiddleware\PageCache::storeResponse()
 * @see \Drupal\canvas_personalization\EventSubscriber\PersonalizationResponseSubscriber
 */
final class SegmentAwareResponsePolicy implements ResponsePolicyInterface {

  public function __construct(
    private readonly SegmentEvaluator $segmentEvaluator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(Response $response, Request $request): ?string {
    if (!$response instanceof CacheableResponseInterface) {
      return NULL;
    }
    $segment_ids = SegmentEvaluator::extractSegmentIds($response->getCacheableMetadata()->getCacheTags());
    if ($segment_ids === []) {
      return NULL;
    }
    $declared = $this->segmentEvaluator->getDeclaredCacheability($segment_ids);
    if ($declared->getCacheMaxAge() === 0) {
      return static::DENY;
    }
    foreach ($declared->getCacheContexts() as $context) {
      if ($context !== 'url' && !\str_starts_with($context, 'url.')) {
        return static::DENY;
      }
    }
    return NULL;
  }

}
