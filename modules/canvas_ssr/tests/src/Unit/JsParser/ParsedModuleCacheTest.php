<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsParser;

use Drupal\canvas_ssr\JsParser\ParsedModule;
use Drupal\canvas_ssr\JsParser\ParsedModuleCache;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests keeping parse results between requests, and evicting them.
 *
 * Each ParsedModuleCache is a request, ended by destruct(). They share a
 * backend, as requests share the cache.
 */
#[CoversClass(ParsedModuleCache::class)]
#[Group('canvas_ssr')]
final class ParsedModuleCacheTest extends UnitTestCase {

  /**
   * The cache the requests share.
   */
  private MemoryBackend $backend;

  /**
   * How many times the cache was written.
   */
  private int $sets = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1_700_000_000);
    $time->method('getCurrentTime')->willReturn(1_700_000_000);
    $this->backend = new class($time, function (): void {
      $this->sets++;
    }) extends MemoryBackend {

      public function __construct(TimeInterface $time, private readonly \Closure $onSet) {
        parent::__construct($time);
      }

      /**
       * {@inheritdoc}
       */
      public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []): void {
        ($this->onSet)();
        parent::set($cid, $data, $expire, $tags);
      }

    };
  }

  /**
   * Keeps results for later requests, failures included.
   */
  public function testKeepsResults(): void {
    $client = new ParsedModule([['preact', 20, 28]], ['use client']);
    $plain = new ParsedModule([['preact', 20, 28]], []);
    $request = $this->request();
    $request->store('client', $client);
    $request->store('broken', NULL);
    $request->store('plain', $plain);
    // Found within the request, before any write.
    self::assertEquals($client, $request->lookup('client'));
    self::assertEquals($plain, $request->lookup('plain'));
    $request->destruct();

    $next = $this->request();
    self::assertEquals($client, $next->lookup('client'));
    self::assertFalse($next->lookup('broken'));
    self::assertEquals($plain, $next->lookup('plain'));
    self::assertNull($next->lookup('never stored'));
  }

  /**
   * Writes nothing when a request only finds results, until an eviction.
   */
  public function testWritesOnlyWhatChanged(): void {
    $request = $this->request();
    $request->store('a', self::empty());
    $request->destruct();
    self::assertSame(1, $this->sets);

    $request = $this->request();
    $request->lookup('a');
    $request->destruct();
    self::assertSame(1, $this->sets);
  }

  /**
   * Evicts what wasn't used since the last eviction, oldest first.
   */
  public function testEvictsTheLeastRecentlyUsed(): void {
    $request = $this->request();
    for ($i = 0; $i < ParsedModuleCache::MAX_ITEMS; $i++) {
      $request->store("item $i", self::empty());
    }
    $request->destruct();

    // Full, so the next new result evicts the oldest.
    $request = $this->request();
    $request->store('new 1', self::empty());
    $request->destruct();
    [$generation, $items] = $this->stored();
    self::assertSame(1, $generation);
    self::assertCount(ParsedModuleCache::MAX_ITEMS, $items);
    self::assertArrayNotHasKey('item 0', $items);

    // Finding an item used before the eviction marks it used, once.
    $sets = $this->sets;
    $request = $this->request();
    $request->lookup('item 1');
    $request->destruct();
    self::assertSame($sets + 1, $this->sets);
    $request = $this->request();
    $request->lookup('item 1');
    $request->destruct();
    self::assertSame($sets + 1, $this->sets);

    // So the next eviction passes it over, for the oldest one not found.
    $request = $this->request();
    $request->store('new 2', self::empty());
    $request->destruct();
    [$generation, $items] = $this->stored();
    self::assertSame(2, $generation);
    self::assertArrayHasKey('item 1', $items);
    self::assertArrayNotHasKey('item 2', $items);
    self::assertArrayHasKey('new 1', $items);
    self::assertArrayHasKey('new 2', $items);
  }

  /**
   * Merges concurrent requests' writes rather than overwriting them.
   */
  public function testMergesConcurrentRequests(): void {
    // Two requests read the cache before either writes.
    $early = $this->request();
    $early->lookup('anything');
    $late = $this->request();
    $late->lookup('anything');

    // The late one fills the cache and evicts.
    for ($i = 0; $i <= ParsedModuleCache::MAX_ITEMS; $i++) {
      $late->store("late $i", self::empty());
    }
    $late->destruct();
    [$generation] = $this->stored();
    self::assertSame(1, $generation);

    // The early one read an earlier generation, but what it stores is used
    // now, so it's kept, and the oldest of the late one's is evicted.
    $early->store('early', self::empty());
    $early->destruct();
    [$generation, $items] = $this->stored();
    self::assertSame(2, $generation);
    self::assertCount(ParsedModuleCache::MAX_ITEMS, $items);
    self::assertArrayHasKey('early', $items);
    self::assertArrayNotHasKey('late 1', $items);
    self::assertArrayHasKey('late 2', $items);
  }

  /**
   * Returns a parsed module with nothing in it.
   */
  private static function empty(): ParsedModule {
    return new ParsedModule([], []);
  }

  /**
   * Starts a request.
   *
   * @return \Drupal\canvas_ssr\JsParser\ParsedModuleCache
   *   The request's cache.
   */
  private function request(): ParsedModuleCache {
    return new ParsedModuleCache($this->backend, new NullLockBackend());
  }

  /**
   * Reads what's stored.
   *
   * @return array{0: int, 1: array<string, array{used: int}>}
   *   The generation and the items.
   */
  private function stored(): array {
    $cached = $this->backend->get('canvas_ssr.parsed_modules:3');
    self::assertNotFalse($cached);

    return [$cached->data['generation'], $cached->data['items']];
  }

}
