<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Notification;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\canvas\Hook\NotificationCronHook;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the NotificationCronHook and its underlying cleanup methods.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(NotificationCronHook::class)]
#[CoversClass(CanvasNotificationHandler::class)]
#[Group('canvas')]
class NotificationCronHookTest extends CanvasKernelTestBase {

  private function handler(): CanvasNotificationHandler {
    return $this->container->get(CanvasNotificationHandler::class);
  }

  private function notificationCount(?string $type = NULL): int {
    $query = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n');
    if ($type !== NULL) {
      $query->condition('n.type', $type);
    }
    return (int) $query->countQuery()->execute()?->fetchField();
  }

  private function readCount(): int {
    return (int) $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_READ_TABLE, 'r')
      ->countQuery()
      ->execute()
      ?->fetchField();
  }

  /**
   * Purge stale processing tests.
   */
  public function testPurgesStaleProcessingNotifications(): void {
    $handler = $this->handler();
    // 31 minutes ago.
    $handler->create([
      'type' => 'processing',
      'title' => 'Stale sync',
      'message' => 'Syncing...',
      'key' => 'sync',
      'timestamp' => (int) (microtime(TRUE) * 1000) - 1860000,
    ]);

    $handler->purgeStaleProcessing();

    self::assertSame(0, $this->notificationCount('processing'));
  }

  public function testCreatesErrorForPurgedProcessing(): void {
    $handler = $this->handler();
    $handler->create([
      'type' => 'processing',
      'title' => 'Stale sync',
      'message' => 'Syncing data...',
      'key' => 'sync',
      'timestamp' => (int) (microtime(TRUE) * 1000) - 1860000,
    ]);

    $handler->purgeStaleProcessing();

    self::assertSame(1, $this->notificationCount('error'));

    $row = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n')
      ->condition('type', 'error')
      ->execute()
      ?->fetchObject();
    self::assertSame('sync', $row->key);
    self::assertSame('Operation timed out', $row->title);
    self::assertSame('Syncing data...', $row->message);
  }

  public function testLeavesRecentProcessingAlone(): void {
    $handler = $this->handler();
    // 10 minutes ago — within threshold.
    $handler->create([
      'type' => 'processing',
      'title' => 'Recent sync',
      'message' => 'Syncing...',
      'timestamp' => (int) (microtime(TRUE) * 1000) - 600000,
    ]);

    $handler->purgeStaleProcessing();

    self::assertSame(1, $this->notificationCount('processing'));
  }

  public function testPurgeDoesNotAffectNonProcessingTypes(): void {
    $handler = $this->handler();
    $old_timestamp = (int) (microtime(TRUE) * 1000) - 1860000;
    foreach (['info', 'warning', 'error', 'success'] as $type) {
      $handler->create([
        'type' => $type,
        'title' => "Old $type",
        'message' => 'M',
        'timestamp' => $old_timestamp,
      ]);
    }

    $handler->purgeStaleProcessing();

    // All 4 non-processing notifications should remain.
    self::assertSame(4, $this->notificationCount());
    self::assertSame(0, $this->notificationCount('processing'));
  }

  public function testPurgeHandlesMultipleStaleNotifications(): void {
    $handler = $this->handler();
    $old_timestamp = (int) (microtime(TRUE) * 1000) - 1860000;
    $handler->create([
      'type' => 'processing',
      'title' => 'Sync A',
      'message' => 'A',
      'key' => 'a',
      'timestamp' => $old_timestamp,
    ]);
    $handler->create([
      'type' => 'processing',
      'title' => 'Sync B',
      'message' => 'B',
      'key' => 'b',
      'timestamp' => $old_timestamp - 1000,
    ]);

    $handler->purgeStaleProcessing();

    self::assertSame(0, $this->notificationCount('processing'));
    self::assertSame(2, $this->notificationCount('error'));
  }

  /**
   * Delete expired tests.
   */
  public function testDeletesExpiredNotificationsOfAllTypes(): void {
    $handler = $this->handler();
    // 31 days ago.
    $old_timestamp = (int) (microtime(TRUE) * 1000) - 2678400000;
    foreach (['info', 'warning', 'error', 'success', 'processing'] as $type) {
      $handler->create([
        'type' => $type,
        'title' => "Old $type",
        'message' => 'M',
        'timestamp' => $old_timestamp,
      ]);
    }

    $handler->deleteExpired();

    self::assertSame(0, $this->notificationCount());
  }

  public function testDeletesExpiredReadEntries(): void {
    $handler = $this->handler();
    $n = $handler->create([
      'type' => 'info',
      'title' => 'Old',
      'message' => 'M',
      'timestamp' => (int) (microtime(TRUE) * 1000) - 2678400000,
    ]);

    // Insert a read entry with the same old timestamp.
    $this->container->get('database')
      ->insert(CanvasNotificationHandler::NOTIFICATION_READ_TABLE)
      ->fields([
        'uid' => 1,
        'notification_id' => $n['id'],
        'timestamp' => (int) (microtime(TRUE) * 1000) - 2678400000,
      ])
      ->execute();

    $handler->deleteExpired();

    self::assertSame(0, $this->notificationCount());
    self::assertSame(0, $this->readCount());
  }

  public function testDeleteExpiredOnlyDeletesOldEntries(): void {
    $handler = $this->handler();
    // Recent notification.
    $recent = $handler->create([
      'type' => 'info',
      'title' => 'Recent',
      'message' => 'M',
    ]);
    // Old notification.
    $handler->create([
      'type' => 'info',
      'title' => 'Old',
      'message' => 'M',
      'timestamp' => (int) (microtime(TRUE) * 1000) - 2678400000,
    ]);

    // Mark the recent notification as read.
    $handler->markRead(1, [$recent['id']]);

    $handler->deleteExpired();

    self::assertSame(1, $this->notificationCount());
    self::assertSame(1, $this->readCount());
  }

}
