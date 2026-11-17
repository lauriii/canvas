<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\Log;

use Drupal\canvas_ssr\Log\ThrottledLogger;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\KeyValueStore\MemoryStorage;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests a recurring message is logged once an interval, however PHP runs.
 */
#[CoversClass(ThrottledLogger::class)]
#[Group('canvas_ssr')]
final class ThrottledLoggerTest extends UnitTestCase {

  /**
   * The intervals the tests use, by level.
   */
  private const INTERVALS = ['warning' => 3600, 'error' => 60];

  /**
   * Tests each message is logged once, shared by every logger on the store.
   *
   * Each logger stands for a PHP process, or a request in one.
   */
  public function testEachMessageIsLoggedOnceAnInterval(): void {
    $store = self::store();
    $logged = new CollectingLogger();
    for ($request = 0; $request < 3; $request++) {
      $logger = new ThrottledLogger($logged, $store, self::INTERVALS);
      $logger->warning('Off: {message}', ['message' => 'root A']);
      $logger->warning('Off: {message}', ['message' => 'root B']);
    }

    self::assertSame(['Off: root A', 'Off: root B'], $logged->at('warning'));

    // Once the interval is over, it's logged again.
    $store->deleteAll();
    (new ThrottledLogger($logged, $store, self::INTERVALS))->warning('Off: {message}', ['message' => 'root A']);
    self::assertSame(['Off: root A', 'Off: root B', 'Off: root A'], $logged->at('warning'));
  }

  /**
   * Tests each level has its own interval, and unlisted levels have none.
   */
  public function testIntervalsAreByLevel(): void {
    $store = self::store();
    $logged = new CollectingLogger();
    $logger = new ThrottledLogger($logged, $store, self::INTERVALS);

    foreach (['warning', 'error', 'info'] as $level) {
      $logger->log($level, 'Same');
      $logger->log($level, 'Same');
    }

    self::assertSame(['Same'], $logged->at('warning'));
    self::assertSame(['Same'], $logged->at('error'));
    self::assertSame(['Same', 'Same'], $logged->at('info'));
    \assert(\property_exists($store, 'expires'));
    self::assertSame([3600, 60], \array_values($store->expires));
  }

  /**
   * Tests a message is still logged when the store fails.
   */
  public function testMessageIsLoggedWhenTheStoreFails(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('setWithExpireIfNotExists')->willThrowException(new \RuntimeException('Down.'));
    $logged = new CollectingLogger();

    (new ThrottledLogger($logged, $store, self::INTERVALS))->warning('Off');

    self::assertSame(['Off'], $logged->at('warning'));
  }

  /**
   * Returns an expirable store in memory, which records expiries.
   *
   * Its entries never expire; deleting them stands for that.
   */
  private static function store(): KeyValueStoreExpirableInterface {
    return new class('logged') extends MemoryStorage implements KeyValueStoreExpirableInterface {

      /**
       * The expiry each entry was set with, in seconds, by key.
       *
       * @var array<string, int>
       */
      public array $expires = [];

      /**
       * {@inheritdoc}
       */
      public function setWithExpire($key, $value, $expire): void {
        $this->expires[$key] = $expire;
        $this->set($key, $value);
      }

      /**
       * {@inheritdoc}
       */
      public function setWithExpireIfNotExists($key, $value, $expire): bool {
        if (!$this->setIfNotExists($key, $value)) {
          return FALSE;
        }
        $this->expires[$key] = $expire;

        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function setMultipleWithExpire(array $data, $expire): void {
        foreach ($data as $key => $value) {
          $this->setWithExpire($key, $value, $expire);
        }
      }

    };
  }

}
