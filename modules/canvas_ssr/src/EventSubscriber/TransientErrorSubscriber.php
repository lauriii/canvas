<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\EventSubscriber;

use Drupal\canvas_ssr\Renderer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Makes a page with an island of a transient error expire with its max-age.
 *
 * Such an island is cached briefly, or not at all, since the error may have
 * passed by the next view. Dynamic page cache and the render cache go by
 * max-age, but Drupal's page cache ignores it, and the Cache-Control header
 * says the site's page max-age. So without this, an anonymous visitor could
 * get the page with its CSR fallback until something invalidates it.
 *
 * Only pages with Renderer::TRANSIENT_ERROR_TAG change. A page whose
 * max-age is 0 isn't cached at all, as Drupal does with a server error: the
 * page cache's kill switch keeps it out, and the headers say so. Otherwise
 * Expires is set to its max-age from now, which the page cache uses, and a
 * public Cache-Control's max-age is lowered to it, which other caches use.
 *
 * It runs after FinishResponseSubscriber, which sets those headers, and
 * HtmlResponseSubscriber, whose placeholders add to the page's cacheability.
 * The page cache stores a response only after every subscriber has run.
 *
 * @see \Drupal\canvas_ssr\Renderer::TRANSIENT_ERROR_TAG
 * @see \Drupal\page_cache\StackMiddleware\PageCache::storeResponse()
 * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber
 */
final class TransientErrorSubscriber implements EventSubscriberInterface {

  /**
   * The priority it runs at, after Drupal's subscribers at 0.
   */
  public const int PRIORITY = -50;

  /**
   * The Expires date Drupal gives a response that mustn't be cached.
   *
   * @see \Drupal\Core\EventSubscriber\FinishResponseSubscriber::setExpiresNoCache()
   */
  private const string EXPIRED = 'Sun, 19 Nov 1978 05:00:00 GMT';

  /**
   * Constructs a TransientErrorSubscriber.
   *
   * @param \Drupal\Core\PageCache\ResponsePolicy\KillSwitch $killSwitch
   *   Keeps a response out of the page cache.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time, which the page cache compares Expires with.
   */
  public function __construct(
    private readonly KillSwitch $killSwitch,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['onResponse', self::PRIORITY]];
  }

  /**
   * Sets how long caches keep a page with an island of a transient error.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$event->isMainRequest() || !$response instanceof CacheableResponseInterface) {
      return;
    }
    $cacheability = $response->getCacheableMetadata();
    $max_age = $cacheability->getCacheMaxAge();
    if (!\in_array(Renderer::TRANSIENT_ERROR_TAG, $cacheability->getCacheTags(), TRUE) || $max_age === Cache::PERMANENT) {
      return;
    }

    if ($max_age === 0) {
      $this->killSwitch->trigger();
      $response->headers->set('Cache-Control', 'must-revalidate, no-cache, private');
      $response->headers->set('Expires', self::EXPIRED);
      return;
    }

    $expires = \DateTimeImmutable::createFromFormat('U', (string) ($this->time->getRequestTime() + $max_age));
    \assert($expires instanceof \DateTimeImmutable);
    $response->setExpires($expires);
    if ($response->headers->hasCacheControlDirective('public')) {
      foreach (['max-age', 's-maxage'] as $directive) {
        $current = $response->headers->getCacheControlDirective($directive);
        if ($current !== NULL && (int) $current > $max_age) {
          $response->headers->addCacheControlDirective($directive, (string) $max_age);
        }
      }
    }
  }

}
