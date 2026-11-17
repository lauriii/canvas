<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit;

use Drupal\canvas_ssr\HostStatus;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Tests\canvas_ssr\Unit\JsRunner\RecordingRunner;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how a host records what its runner can't enforce.
 */
#[CoversClass(HostStatus::class)]
#[Group('canvas_ssr')]
final class HostStatusTest extends UnitTestCase {

  /**
   * Tests a runner that enforces everything writes nothing.
   */
  public function testNoLimitationsWriteNothing(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->never())->method('get');
    $store->expects($this->never())->method('setWithExpire');

    (new HostStatus($store, new RecordingRunner()))->recordLimitations();
  }

  /**
   * Tests limitations are recorded once per process, keyed by host.
   */
  public function testLimitationsAreRecordedOnce(): void {
    $runner = new RecordingRunner();
    $runner->limitations = ['It can\'t read /proc.'];
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(NULL);
    $store->expects($this->once())->method('setWithExpire')->with(
      'limits:' . HostStatus::host(),
      $this->callback(static fn(array $record): bool => $record['limitations'] === ['It can\'t read /proc.'] && \abs($record['at'] - \time()) < 5),
      3600,
    );

    $status = new HostStatus($store, $runner);
    $status->recordLimitations();
    $status->recordLimitations();
  }

  /**
   * Tests a recent record that says the same isn't written again.
   */
  public function testRecentRecordIsKept(): void {
    $runner = new RecordingRunner();
    $runner->limitations = ['It can\'t read /proc.'];
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('get')->willReturn(['limitations' => ['It can\'t read /proc.'], 'at' => \time() - 60]);
    $store->expects($this->never())->method('setWithExpire');

    (new HostStatus($store, $runner))->recordLimitations();
  }

}
