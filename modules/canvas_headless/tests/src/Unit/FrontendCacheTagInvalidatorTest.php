<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LogLevel;

/**
 * Tests announcing invalidated cache tags to headless frontends.
 */
#[CoversClass(FrontendCacheTagInvalidator::class)]
#[Group('canvas_headless')]
final class FrontendCacheTagInvalidatorTest extends UnitTestCase {

  /**
   * Tests that tags are announced once per request to every opted-in URL.
   */
  public function testAnnouncesCollectedTags(): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
      new Response(204),
      new ConnectException('Connection refused', new Request('POST', 'https://down.example/api/canvas/revalidate')),
      new Response(500, [], 'A body that is not logged'),
      new Response(301, ['Location' => 'https://elsewhere.example/']),
    ]));
    $handler->push(Middleware::history($history));
    $warnings = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$warnings): void {
      $warnings[] = $context;
    });

    $values = [];
    $failed = new MemoryStorage('failed');
    $invalidator = $this->invalidator(
      [
        'https://www.example.com/api/canvas/revalidate',
        'https://down.example/api/canvas/revalidate',
        'https://broken.example/api/canvas/revalidate',
        'https://moved.example/api/canvas/revalidate',
        'javascript:alert(1)',
      ],
      static fn () => new Client(['handler' => $handler]),
      $this->loggerFactory($logger),
      $values,
      $failed,
    );
    $invalidator->invalidateTags(['node:1', 'node_list']);
    $invalidator->invalidateTags(['node:1', 'config:system.site', '123']);
    $invalidator->destruct();
    // Nothing is left to announce.
    $invalidator->destruct();

    \assert(\is_array($history));
    self::assertCount(4, $history);
    $ids = [];
    foreach ($history as $index => $transaction) {
      $request = $transaction['request'];
      self::assertSame('POST', $request->getMethod());
      self::assertSame(['https://www.example.com', 'https://down.example', 'https://broken.example', 'https://moved.example'][$index] . '/api/canvas/revalidate', (string) $request->getUri());
      self::assertFalse($request->hasHeader('Authorization'));
      self::assertFalse($transaction['options']['allow_redirects']);
      $ids[] = json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR)['id'];
    }
    // One ID per URL, each storing the tags; numeric tags stay strings.
    self::assertCount(4, array_unique($ids));
    self::assertMatchesRegularExpression('/^[\w-]{43}$/', $ids[0]);
    foreach ($ids as $id) {
      self::assertSame(['node:1', 'node_list', 'config:system.site', '123'], $values[$id]);
    }
    // In completion order.
    self::assertEqualsCanonicalizing([
      ['@url' => 'https://down.example/api/canvas/revalidate', '@message' => 'Connection refused'],
      ['@url' => 'https://broken.example/api/canvas/revalidate', '@message' => 'HTTP 500'],
      ['@url' => 'https://moved.example/api/canvas/revalidate', '@message' => 'HTTP 301'],
    ], $warnings);

    // Failed URLs are remembered.
    self::assertEqualsCanonicalizing([
      'https://down.example/api/canvas/revalidate' => TRUE,
      'https://broken.example/api/canvas/revalidate' => TRUE,
      'https://moved.example/api/canvas/revalidate' => TRUE,
    ], $failed->getAll());
  }

  /**
   * Tests that a URL that missed an announcement is told to expire everything.
   */
  public function testRetriesFailedUrls(): void {
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
      new Response(500),
      new Response(204),
      new Response(204),
      new Response(204),
    ]));
    $handler->push(Middleware::history($history));
    $values = [];
    $failed = new MemoryStorage('failed');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $invalidator = $this->invalidator(
      ['https://www.example.com/api/canvas/revalidate'],
      static fn () => new Client(['handler' => $handler]),
      $this->loggerFactory($logger),
      $values,
      $failed,
    );

    // Nothing failed yet: cron announces nothing.
    $invalidator->retryFailed();
    self::assertSame([], $values);

    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    self::assertSame(['https://www.example.com/api/canvas/revalidate'], \array_keys($failed->getAll()));

    // The next announcement, here from cron, expires everything.
    $invalidator->retryFailed();
    self::assertSame([], $failed->getAll());
    // Then announcements carry the invalidated tags again.
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    $invalidator->retryFailed();

    \assert(\is_array($history));
    self::assertCount(3, $history);
    self::assertSame([['node:1'], ['http_response'], ['node:2']], array_values($values));
  }

  /**
   * Tests that http_response replaces other tags, and too many tags.
   */
  public function testAnnouncesHttpResponseForEverything(): void {
    $handler = HandlerStack::create(new MockHandler([new Response(204), new Response(204)]));
    $store = [];
    $invalidator = $this->invalidator(
      ['https://www.example.com/api/canvas/revalidate'],
      static fn () => new Client(['handler' => $handler]),
      $this->createMock(LoggerChannelFactoryInterface::class),
      $store,
    );

    $invalidator->invalidateTags(['node:1', 'http_response']);
    $invalidator->invalidateTags(['node:2']);
    $invalidator->destruct();
    $invalidator->invalidateTags(\array_map(static fn (int $i): string => "node:$i", range(1, 1001)));
    $invalidator->destruct();

    self::assertSame([['http_response'], ['http_response']], array_values($store));
  }

  /**
   * Tests that a failure is logged, not thrown to the kernel.
   */
  public function testLogsFailures(): void {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('log')->with(LogLevel::ERROR);
    $invalidator = $this->invalidator(
      ['https://www.example.com/api/canvas/revalidate'],
      static fn () => throw new \RuntimeException('The HTTP client is broken.'),
      $this->loggerFactory($logger),
    );
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
  }

  /**
   * Tests that nothing is announced without opted-in URLs.
   */
  public function testWithoutFrontends(): void {
    $invalidator = $this->invalidator(
      [],
      fn () => $this->fail('The HTTP client is not used.'),
      $this->createMock(LoggerChannelFactoryInterface::class),
    );
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
    $this->addToAssertionCount(1);
  }

  /**
   * Returns a logger factory for the canvas_headless channel.
   */
  private function loggerFactory(LoggerChannelInterface $logger): LoggerChannelFactoryInterface {
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('canvas_headless')->willReturn($logger);
    return $logger_factory;
  }

  /**
   * Creates the invalidator with the given URLs and in-memory storage.
   *
   * @param list<string> $urls
   *   The cache invalidation URLs.
   * @param array<string, mixed> $values
   *   The stored tags, by ID; expiry is not covered.
   */
  private function invalidator(array $urls, \Closure $http_client, LoggerChannelFactoryInterface $logger_factory, array &$values = [], ?MemoryStorage $failed = NULL): FrontendCacheTagInvalidator {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('setWithExpire')->willReturnCallback(function (string $key, mixed $value) use (&$values): void {
      $values[$key] = $value;
    });
    $key_value_expirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value_expirable->method('get')->willReturn($store);
    $key_value = $this->createMock(KeyValueFactoryInterface::class);
    $key_value->method('get')->willReturn($failed ?? new MemoryStorage('failed'));
    return new FrontendCacheTagInvalidator(
      $http_client,
      fn () => $this->getConfigFactoryStub(['canvas_headless.settings' => ['cache_invalidation_urls' => $urls]]),
      fn () => $key_value_expirable,
      fn () => $key_value,
      fn () => $logger_factory,
    );
  }

}
