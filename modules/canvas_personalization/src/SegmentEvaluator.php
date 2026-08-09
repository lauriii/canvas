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
   * Memoized evaluation results, keyed by request and segment ID.
   *
   * @var array<string, \Drupal\canvas_personalization\SegmentMatch>
   */
  private array $matches = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RequestStack $requestStack,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * Evaluates a segment against the current request.
   */
  public function evaluate(string $segment_id): SegmentMatch {
    $key = $this->requestKey() . ':' . $segment_id;
    if (isset($this->matches[$key])) {
      return $this->matches[$key];
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

    return $this->matches[$key] = new SegmentMatch($matched, $cacheability);
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

  private function requestKey(): string {
    $request = $this->requestStack->getCurrentRequest();
    return $request instanceof Request ? (string) \spl_object_id($request) : 'no-request';
  }

}
