<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\EventSubscriber;

use Drupal\canvas_personalization\SegmentEvaluator;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Translates a finite personalization max-age into an Expires header.
 *
 * The internal page cache expires entries via the Expires header only — it
 * deliberately ignores max-age (see PageCache::storeResponse()). Without
 * this, a page personalized on a time-based condition (day of week) would be
 * cached with its current variant forever. HTTP clients ignore Expires when a
 * Cache-Control max-age directive is present (RFC 9111), and Drupal always
 * sends one, so this only affects the internal page cache.
 *
 * Like the response policy, this derives from the response's segment cache
 * tags and the segments' configuration-declared condition cacheability, so it
 * also applies to responses served from context-aware caches. Responses
 * without segment tags are never altered.
 *
 * @see \Drupal\canvas_personalization\PageCache\SegmentAwareResponsePolicy
 */
final class PersonalizationResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly SegmentEvaluator $segmentEvaluator,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run before FinishResponseSubscriber (priority 0), which sets a
    // past-dated Expires on cacheable responses only when none is set yet.
    return [KernelEvents::RESPONSE => ['onRespond', 10]];
  }

  public function onRespond(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof CacheableResponseInterface) {
      return;
    }
    $segment_ids = SegmentEvaluator::extractSegmentIds($response->getCacheableMetadata()->getCacheTags());
    if ($segment_ids === []) {
      return;
    }
    $max_age = $this->segmentEvaluator->getDeclaredCacheability($segment_ids)->getCacheMaxAge();
    // PERMANENT (-1) needs no expiry; 0 is handled by the response policy.
    if ($max_age <= 0) {
      return;
    }
    // Another layer may have bounded the response even tighter.
    $response_max_age = $response->getCacheableMetadata()->getCacheMaxAge();
    if ($response_max_age > 0) {
      $max_age = \min($max_age, $response_max_age);
    }
    $expire_at = $this->time->getRequestTime() + $max_age;
    $existing = $response->getExpires();
    if ($existing === NULL || $existing->getTimestamp() > $expire_at) {
      $expires = \DateTimeImmutable::createFromFormat('U', (string) $expire_at);
      \assert($expires instanceof \DateTimeImmutable);
      $response->setExpires($expires);
    }
  }

}
