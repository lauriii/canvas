<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\EventSubscriber;

use Drupal\canvas_ssr\EventSubscriber\TransientErrorSubscriber;
use Drupal\canvas_ssr\Renderer;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\PageCache\ResponsePolicy\KillSwitch;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests a page with an island of a transient error expires with its max-age.
 */
#[CoversClass(TransientErrorSubscriber::class)]
#[Group('canvas_ssr')]
final class TransientErrorSubscriberTest extends UnitTestCase {

  /**
   * The request time the subscriber is given.
   */
  private const int NOW = 1_700_000_000;

  /**
   * Tests a page without the tag keeps its headers, whatever its max-age.
   */
  public function testPageWithoutTheTagIsLeftAlone(): void {
    $response = self::response(60, tagged: FALSE);
    $kill_switch = new KillSwitch();

    $this->respond($response, $kill_switch);

    self::assertSame('max-age=3600, public', $response->headers->get('Cache-Control'));
    self::assertSame('Sun, 19 Nov 1978 05:00:00 GMT', $response->headers->get('Expires'));
    self::assertNull($kill_switch->check($response, new Request()));
  }

  /**
   * Tests a tagged page expires from every cache with its max-age.
   */
  public function testTaggedPageExpiresWithItsMaxAge(): void {
    $response = self::response(60);
    $kill_switch = new KillSwitch();

    $this->respond($response, $kill_switch);

    self::assertSame(self::NOW + 60, $response->getExpires()?->getTimestamp());
    self::assertSame('60', $response->headers->getCacheControlDirective('max-age'));
    self::assertTrue($response->headers->hasCacheControlDirective('public'));
    self::assertNull($kill_switch->check($response, new Request()));
  }

  /**
   * Tests a tagged page with max-age 0 is kept out of every cache.
   *
   * The page cache would keep a page whose Expires has passed for good, so
   * it takes the kill switch.
   */
  public function testTaggedPageWithNoMaxAgeIsNotCached(): void {
    $response = self::response(0);
    $kill_switch = new KillSwitch();

    $this->respond($response, $kill_switch);

    self::assertSame(ResponsePolicyInterface::DENY, $kill_switch->check($response, new Request()));
    self::assertSame('must-revalidate, no-cache, private', $response->headers->get('Cache-Control'));
    self::assertSame('Sun, 19 Nov 1978 05:00:00 GMT', $response->headers->get('Expires'));
  }

  /**
   * Tests a private page only gets Expires, for the page cache.
   *
   * A shorter max-age a page already has is kept too.
   */
  public function testPrivateOrShorterCacheControlIsKept(): void {
    $private = self::response(60);
    $private->headers->set('Cache-Control', 'must-revalidate, no-cache, private');
    $shorter = self::response(60);
    $shorter->headers->set('Cache-Control', 'public, max-age=30');

    $this->respond($private, new KillSwitch());
    $this->respond($shorter, new KillSwitch());

    self::assertSame('must-revalidate, no-cache, private', $private->headers->get('Cache-Control'));
    self::assertSame(self::NOW + 60, $private->getExpires()?->getTimestamp());
    self::assertSame('30', $shorter->headers->getCacheControlDirective('max-age'));
  }

  /**
   * Tests a subrequest's response is left alone.
   */
  public function testSubrequestIsLeftAlone(): void {
    $response = self::response(60);

    $this->respond($response, new KillSwitch(), HttpKernelInterface::SUB_REQUEST);

    self::assertSame('max-age=3600, public', $response->headers->get('Cache-Control'));
  }

  /**
   * Tests a permanent max-age changes nothing, though the tag is there.
   */
  public function testPermanentMaxAgeIsLeftAlone(): void {
    $response = self::response(Cache::PERMANENT);

    $this->respond($response, new KillSwitch());

    self::assertSame('Sun, 19 Nov 1978 05:00:00 GMT', $response->headers->get('Expires'));
  }

  /**
   * Returns a page as FinishResponseSubscriber leaves a cacheable one.
   */
  private static function response(int $max_age, bool $tagged = TRUE): CacheableResponse {
    $response = new CacheableResponse('page');
    $response->headers->set('Cache-Control', 'public, max-age=3600');
    $response->headers->set('Expires', 'Sun, 19 Nov 1978 05:00:00 GMT');
    $response->getCacheableMetadata()->setCacheMaxAge($max_age);
    if ($tagged) {
      $response->getCacheableMetadata()->addCacheTags([Renderer::TRANSIENT_ERROR_TAG]);
    }

    return $response;
  }

  /**
   * Runs the subscriber on a response.
   */
  private function respond(CacheableResponse $response, KillSwitch $kill_switch, int $type = HttpKernelInterface::MAIN_REQUEST): void {
    $time = $this->createStub(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(self::NOW);
    $event = new ResponseEvent($this->createStub(HttpKernelInterface::class), new Request(), $type, $response);

    (new TransientErrorSubscriber($kill_switch, $time))->onResponse($event);
  }

}
