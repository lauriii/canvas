<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization;

use Drupal\canvas_personalization\Entity\Segment;
use Drupal\canvas_personalization\Entity\SegmentInterface;
use Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Error;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Evaluates segments against the current request, with correct cacheability.
 *
 * Evaluation results are memoized per request and per segment, so a segment
 * referenced by multiple switches on one page is evaluated once.
 *
 * Failure semantics are fail closed: a missing segment, an unpublished
 * segment, or a condition that throws (for example, an unreachable
 * third-party segmentation provider) evaluates as "not matching" — the
 * visitor gets the default variant, never an error page and never a wrong
 * variant.
 *
 * Separately from evaluation, ::getDeclaredCacheability() derives what a set
 * of segments' results *can* vary by, purely from configuration. The
 * page-cache integration uses that — never per-request evaluation state —
 * because a personalized response may be served by a context-aware cache
 * without any evaluation running on that request.
 *
 * @see docs/personalization.md
 */
final class SegmentEvaluator {

  /**
   * The cache tag prefix identifying segment config entities on responses.
   */
  public const string CACHE_TAG_PREFIX = 'config:canvas_personalization.segment.';

  /**
   * Memoized evaluation results, keyed by request object, then segment ID.
   *
   * Keyed by the request object itself, not by anything derived from it: PHP
   * recycles an object's ID once it is garbage collected, so a derived key
   * would let a later request read an earlier one's result — a silently wrong
   * variant. A weak map also bounds the store for free: a request's results
   * are dropped when the request itself is, so a process handling many
   * requests (tests, subrequests, a persistent-kernel runtime) accumulates
   * nothing.
   *
   * @var \WeakMap<\Symfony\Component\HttpFoundation\Request, array<string, \Drupal\canvas_personalization\SegmentMatch>>
   */
  private \WeakMap $matches;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
  ) {
    $this->matches = new \WeakMap();
  }

  /**
   * Evaluates a segment against the current request.
   */
  public function evaluate(string $segment_id): SegmentMatch {
    // Without a request there is nothing to memoize against: evaluating twice
    // is correct, sharing one request-less scope is not.
    $request = $this->requestStack->getCurrentRequest();
    $memoized = $request instanceof Request ? ($this->matches[$request] ?? []) : [];
    if (isset($memoized[$segment_id])) {
      return $memoized[$segment_id];
    }

    $cacheability = $this->getDeclaredCacheability([$segment_id]);
    $segment = $this->entityTypeManager->getStorage(Segment::ENTITY_TYPE_ID)->load($segment_id);
    $matched = FALSE;
    if ($segment instanceof SegmentInterface && $segment->status()) {
      // A segment with zero rules (such as the locked `default` segment)
      // matches every visitor.
      $matched = TRUE;
      foreach ($segment->getSegmentRulesPluginCollection() as $condition) {
        \assert($condition instanceof SegmentConditionInterface);
        try {
          // Keep evaluating after a non-match: every condition's result is
          // part of the declared cacheability regardless, and evaluation may
          // have provider-side effects such as warming a membership cache.
          $matched = $condition->evaluate() && $matched;
        }
        catch (\Throwable $exception) {
          Error::logException($this->logger, $exception);
          $matched = FALSE;
        }
      }
    }

    $match = new SegmentMatch($matched, $cacheability);
    if ($request instanceof Request) {
      $this->matches[$request] = ($this->matches[$request] ?? []) + [$segment_id => $match];
    }
    return $match;
  }

  /**
   * The declared cacheability of a set of segments.
   *
   * Derived from configuration alone — each enabled segment's conditions'
   * declared cache contexts and minimum max-age, plus one config cache tag
   * per segment ID (by literal string, so missing segments still invalidate
   * affected pages when created). No condition is evaluated; this works on
   * requests where a cache already served the personalized response.
   *
   * A disabled or missing segment contributes only its tag: its result
   * cannot vary by request context until it is enabled, and enabling it
   * invalidates via the tag.
   *
   * @param list<string> $segment_ids
   */
  public function getDeclaredCacheability(array $segment_ids): CacheableMetadata {
    $result = new CacheableMetadata();
    $storage = $this->entityTypeManager->getStorage(Segment::ENTITY_TYPE_ID);
    foreach (\array_unique($segment_ids) as $segment_id) {
      $result->addCacheTags([self::CACHE_TAG_PREFIX . $segment_id]);
      $segment = $storage->load($segment_id);
      if (!$segment instanceof SegmentInterface || !$segment->status()) {
        continue;
      }
      foreach ($segment->getSegmentRulesPluginCollection() as $condition) {
        \assert($condition instanceof SegmentConditionInterface);
        $result->addCacheContexts($condition->getCacheContexts());
        $result->mergeCacheMaxAge($condition->getCacheMaxAge());
        // Segment conditions MUST NOT set cache tags; SegmentConditionBase
        // enforces this structurally, but the interface cannot for direct
        // implementations. Discard and report.
        if ($condition->getCacheTags() !== []) {
          $this->logger->error('The %plugin_id segment condition returned cache tags; segment conditions must not set cache tags, so they were ignored.', ['%plugin_id' => $condition->getPluginId()]);
        }
      }
    }
    return $result;
  }

  /**
   * Extracts segment IDs from a list of cache tags.
   *
   * @param string[] $cache_tags
   *
   * @return list<string>
   */
  public static function extractSegmentIds(array $cache_tags): array {
    $ids = [];
    foreach ($cache_tags as $tag) {
      if (\str_starts_with($tag, self::CACHE_TAG_PREFIX)) {
        $ids[] = \substr($tag, \strlen(self::CACHE_TAG_PREFIX));
      }
    }
    return $ids;
  }

}
