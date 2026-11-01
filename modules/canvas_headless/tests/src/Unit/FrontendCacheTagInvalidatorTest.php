<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\Cache\FrontendCacheTagInvalidator;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests announcing invalidated cache tags to headless frontends.
 */
#[CoversClass(FrontendCacheTagInvalidator::class)]
#[Group('canvas_headless')]
final class FrontendCacheTagInvalidatorTest extends UnitTestCase {

  /**
   * Tests that tags are announced once per request to every frontend.
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

    $invalidator = $this->invalidator(
      [
        ['url' => 'https://www.example.com', 'components' => []],
        ['url' => 'https://down.example', 'components' => []],
        ['url' => 'https://broken.example', 'components' => []],
        ['url' => 'https://moved.example', 'components' => []],
        ['url' => 'javascript:alert(1)', 'components' => []],
      ],
      static fn () => new Client(['handler' => $handler]),
      $this->loggerFactory($logger),
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
    self::assertCount(1, array_unique($ids));
    self::assertMatchesRegularExpression('/^[\w-]{43}$/', $ids[0]);
    // In completion order.
    self::assertEqualsCanonicalizing([
      ['@url' => 'https://down.example/api/canvas/revalidate', '@message' => 'Connection refused'],
      ['@url' => 'https://broken.example/api/canvas/revalidate', '@message' => 'HTTP 500'],
      ['@url' => 'https://moved.example/api/canvas/revalidate', '@message' => 'HTTP 301'],
    ], $warnings);

    $response = $invalidator->getTags($ids[0]);
    // Numeric tags stay strings.
    self::assertSame('{"tags":["node:1","node_list","config:system.site","123"]}', $response->getContent());
    self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    $this->expectException(NotFoundHttpException::class);
    $invalidator->getTags('unknown');
  }

  /**
   * Tests that http_response replaces other tags, and too many tags.
   */
  public function testAnnouncesHttpResponseForEverything(): void {
    $handler = HandlerStack::create(new MockHandler([new Response(204), new Response(204)]));
    $store = [];
    $invalidator = $this->invalidator(
      [['url' => 'https://www.example.com', 'components' => []]],
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
      [['url' => 'https://www.example.com', 'components' => []]],
      static fn () => throw new \RuntimeException('The HTTP client is broken.'),
      $this->loggerFactory($logger),
    );
    $invalidator->invalidateTags(['node:1']);
    $invalidator->destruct();
  }

  /**
   * Tests that nothing is announced without frontends.
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
   * Creates the invalidator with the given frontends and in-memory storage.
   *
   * @param array<string, mixed> $values
   *   The stored values, by key; expiry is not covered.
   */
  private function invalidator(array $frontends, \Closure $http_client, LoggerChannelFactoryInterface $logger_factory, array &$values = []): FrontendCacheTagInvalidator {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('setWithExpire')->willReturnCallback(function (string $key, mixed $value) use (&$values): void {
      $values[$key] = $value;
    });
    $store->method('get')->willReturnCallback(function (string $key) use (&$values): mixed {
      return $values[$key] ?? NULL;
    });
    $key_value_expirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $key_value_expirable->method('get')->willReturn($store);
    return new FrontendCacheTagInvalidator(
      $http_client,
      fn () => $this->getConfigFactoryStub(['canvas_headless.settings' => ['frontends' => $frontends]]),
      fn () => $key_value_expirable,
      fn () => $logger_factory,
    );
  }

}
