<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Notification;

use Drupal\canvas\CanvasNotificationHandler;
use Drupal\Component\Uuid\Uuid;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Tests the CanvasNotificationHandler service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(CanvasNotificationHandler::class)]
#[Group('canvas')]
class NotificationServiceTest extends CanvasKernelTestBase {

  private function handler(): CanvasNotificationHandler {
    return $this->container->get(CanvasNotificationHandler::class);
  }

  public function testCreateStoresNotification(): void {
    $result = $this->handler()->create([
      'type' => 'info',
      'title' => 'Test title',
      'message' => 'Test message',
    ]);

    $row = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n')
      ->condition('id', $result['id'])
      ->execute()
      ?->fetchObject();

    self::assertNotFalse($row);
    self::assertSame('info', $row->type);
    self::assertSame('Test title', $row->title);
    self::assertSame('Test message', $row->message);
  }

  public function testCreateAlwaysGeneratesUuid(): void {
    $result = $this->handler()->create([
      'type' => 'info',
      'title' => 'Test',
      'message' => 'Test',
    ]);
    self::assertNotEmpty($result['id']);
    // UUID format check.
    self::assertTrue(Uuid::isValid($result['id']));

    // Passing an id should throw.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Callers must not provide an "id" — it is always auto-generated.');
    $this->handler()->create([
      'id' => 'custom-id',
      'type' => 'info',
      'title' => 'Test',
      'message' => 'Test',
    ]);
  }

  public function testCreateAutoGeneratesTimestamp(): void {
    $before = (int) (microtime(TRUE) * 1000);
    $result = $this->handler()->create([
      'type' => 'info',
      'title' => 'Test',
      'message' => 'Test',
    ]);
    $after = (int) (microtime(TRUE) * 1000);

    self::assertGreaterThanOrEqual($before, $result['timestamp']);
    self::assertLessThanOrEqual($after, $result['timestamp']);

    // Explicit timestamp is preserved.
    $result2 = $this->handler()->create([
      'type' => 'info',
      'title' => 'Test',
      'message' => 'Test',
      'timestamp' => 1000000,
    ]);
    self::assertSame(1000000, $result2['timestamp']);
  }

  #[TestWith([['title' => 'T', 'message' => 'M'], 'type'])]
  #[TestWith([['type' => 'info', 'message' => 'M'], 'title'])]
  #[TestWith([['type' => 'info', 'title' => 'T'], 'message'])]
  public function testCreateValidatesRequiredFields(array $fields, string $missingField): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(\sprintf('The "%s" field is required.', $missingField));
    // @phpstan-ignore argument.type
    $this->handler()->create($fields);
  }

  public function testCreateErrorWithKeyDeletesAcrossTypes(): void {
    $handler = $this->handler();
    $handler->create(['type' => 'processing', 'title' => 'T', 'message' => 'M', 'key' => 'sync']);
    $handler->create(['type' => 'warning', 'title' => 'T', 'message' => 'M', 'key' => 'sync']);

    // Creating error with same key should delete existing.
    $handler->create(['type' => 'error', 'title' => 'Error', 'message' => 'Failed', 'key' => 'sync']);

    $count = (int) $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->condition('key', 'sync')
      ->countQuery()
      ->execute()
      ?->fetchField();

    self::assertSame(1, $count);

    $row = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n')
      ->condition('key', 'sync')
      ->execute()
      ?->fetchObject();
    self::assertSame('error', $row->type);
  }

  public function testCreateWarningWithKeyDeletesAcrossTypes(): void {
    $handler = $this->handler();
    $handler->create(['type' => 'processing', 'title' => 'T', 'message' => 'M', 'key' => 'sync']);
    $handler->create(['type' => 'error', 'title' => 'T', 'message' => 'M', 'key' => 'sync']);

    $handler->create(['type' => 'warning', 'title' => 'Warn', 'message' => 'W', 'key' => 'sync']);

    $count = (int) $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->condition('key', 'sync')
      ->countQuery()
      ->execute()
      ?->fetchField();
    self::assertSame(1, $count);

    $row = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n')
      ->condition('key', 'sync')
      ->execute()
      ?->fetchObject();
    self::assertSame('warning', $row->type);
  }

  public function testCreateProcessingWithKeyDeletesAcrossTypes(): void {
    $handler = $this->handler();
    $handler->create(['type' => 'error', 'title' => 'T', 'message' => 'M', 'key' => 'sync']);
    $handler->create(['type' => 'warning', 'title' => 'T', 'message' => 'M', 'key' => 'sync']);

    $handler->create(['type' => 'processing', 'title' => 'Processing', 'message' => 'P', 'key' => 'sync']);

    $count = (int) $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->condition('key', 'sync')
      ->countQuery()
      ->execute()
      ?->fetchField();
    self::assertSame(1, $count);

    $row = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n')
      ->condition('key', 'sync')
      ->execute()
      ?->fetchObject();
    self::assertSame('processing', $row->type);
  }

  public function testCreateSuccessWithKeyDeletesReplaceableTypes(): void {
    // Insert pre-existing notifications directly to avoid cascading deletes
    // from create() on KEY_REPLACE_TYPES during setup. Ensure the table exists
    // first since we bypass the handler for these inserts.
    $db = $this->container->get('database');
    $db->schema()->createTable(CanvasNotificationHandler::NOTIFICATION_TABLE, CanvasNotificationHandler::schemaDefinition()[CanvasNotificationHandler::NOTIFICATION_TABLE]);
    $db->insert(CanvasNotificationHandler::NOTIFICATION_TABLE)->fields([
      'id' => 'pre-1',
      'type' => 'processing',
      'key' => 'sync',
      'title' => 'T',
      'message' => 'M',
      'timestamp' => 1000000,
    ])->execute();
    $db->insert(CanvasNotificationHandler::NOTIFICATION_TABLE)->fields([
      'id' => 'pre-2',
      'type' => 'error',
      'key' => 'sync',
      'title' => 'T',
      'message' => 'M',
      'timestamp' => 1000001,
    ])->execute();
    // Also insert a pre-existing success — this should NOT be deleted.
    $db->insert(CanvasNotificationHandler::NOTIFICATION_TABLE)->fields([
      'id' => 'pre-3',
      'type' => 'success',
      'key' => 'sync',
      'title' => 'Earlier success',
      'message' => 'M',
      'timestamp' => 1000002,
    ])->execute();

    $this->handler()->create(['type' => 'success', 'title' => 'Done', 'message' => 'OK', 'key' => 'sync']);

    // Processing and error should be deleted; the earlier success and the new
    // success should remain.
    $rows = $db
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n', ['id', 'type'])
      ->condition('key', 'sync')
      ->execute()
      ?->fetchAll();
    self::assertIsArray($rows);
    self::assertCount(2, $rows);
    $types = array_column($rows, 'type');
    self::assertNotContains('processing', $types);
    self::assertNotContains('error', $types);
  }

  public function testCreateInfoWithKeyDeletesReplaceableTypes(): void {
    // Insert pre-existing notifications directly to avoid cascading deletes
    // from create() on KEY_REPLACE_TYPES during setup. Ensure the table exists
    // first since we bypass the handler for these inserts.
    $db = $this->container->get('database');
    $db->schema()->createTable(CanvasNotificationHandler::NOTIFICATION_TABLE, CanvasNotificationHandler::schemaDefinition()[CanvasNotificationHandler::NOTIFICATION_TABLE]);
    $db->insert(CanvasNotificationHandler::NOTIFICATION_TABLE)->fields([
      'id' => 'pre-1',
      'type' => 'processing',
      'key' => 'sync',
      'title' => 'T',
      'message' => 'M',
      'timestamp' => 1000000,
    ])->execute();
    $db->insert(CanvasNotificationHandler::NOTIFICATION_TABLE)->fields([
      'id' => 'pre-2',
      'type' => 'warning',
      'key' => 'sync',
      'title' => 'T',
      'message' => 'M',
      'timestamp' => 1000001,
    ])->execute();
    // Also insert a pre-existing info — this should NOT be deleted.
    $db->insert(CanvasNotificationHandler::NOTIFICATION_TABLE)->fields([
      'id' => 'pre-3',
      'type' => 'info',
      'key' => 'sync',
      'title' => 'Earlier info',
      'message' => 'M',
      'timestamp' => 1000002,
    ])->execute();

    $this->handler()->create(['type' => 'info', 'title' => 'FYI', 'message' => 'Info', 'key' => 'sync']);

    // Processing and warning should be deleted; the earlier info and the new
    // info should remain.
    $rows = $db
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n', ['id', 'type'])
      ->condition('key', 'sync')
      ->execute()
      ?->fetchAll();
    self::assertIsArray($rows);
    self::assertCount(2, $rows);
    $types = array_column($rows, 'type');
    self::assertNotContains('processing', $types);
    self::assertNotContains('warning', $types);
  }

  public function testCreateStoresActionsAsJson(): void {
    $actions = [
      ['label' => 'View logs', 'href' => '/logs'],
      ['label' => 'Retry', 'href' => '/retry'],
    ];
    $result = $this->handler()->create([
      'type' => 'error',
      'title' => 'Failed',
      'message' => 'Something failed',
      'actions' => $actions,
    ]);

    // Verify JSON in DB.
    $row = $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_TABLE, 'n')
      ->fields('n', ['actions'])
      ->condition('id', $result['id'])
      ->execute()
      ?->fetchField();
    self::assertIsString($row);
    self::assertSame($actions, json_decode($row, TRUE));

    // Verify decoded on read.
    $recent = $this->handler()->getRecent(1);
    $found = array_filter($recent, fn(array $n) => $n['id'] === $result['id']);
    $found = reset($found);
    self::assertSame($actions, $found['actions']);
  }

  public function testGetRecentReturnsLimitedResults(): void {
    $handler = $this->handler();
    for ($i = 0; $i < 10; $i++) {
      $handler->create([
        'type' => 'info',
        'title' => "Notification $i",
        'message' => "Message $i",
        'timestamp' => 1000000 + $i,
      ]);
    }

    $results = $handler->getRecent(1, 5);
    self::assertCount(5, $results);
  }

  public function testGetRecentAlwaysIncludesProcessing(): void {
    $handler = $this->handler();
    // Create more non-processing than the limit.
    for ($i = 0; $i < 5; $i++) {
      $handler->create([
        'type' => 'info',
        'title' => "Info $i",
        'message' => "M",
        'timestamp' => 1000000 + $i,
      ]);
    }
    // Create processing notifications.
    $handler->create([
      'type' => 'processing',
      'title' => 'Processing 1',
      'message' => 'In progress',
      'timestamp' => 2000000,
    ]);
    $handler->create([
      'type' => 'processing',
      'title' => 'Processing 2',
      'message' => 'In progress',
      'timestamp' => 2000001,
    ]);

    $results = $handler->getRecent(1, 3);
    $processing = array_filter($results, fn(array $n) => $n['type'] === 'processing');
    $non_processing = array_filter($results, fn(array $n) => $n['type'] !== 'processing');

    // All processing included.
    self::assertCount(2, $processing);
    // Non-processing limited to 3.
    self::assertCount(3, $non_processing);
  }

  public function testGetRecentSortsCorrectly(): void {
    $handler = $this->handler();
    $handler->create([
      'type' => 'info',
      'title' => 'Old info',
      'message' => 'M',
      'timestamp' => 1000000,
    ]);
    $handler->create([
      'type' => 'info',
      'title' => 'New info',
      'message' => 'M',
      'timestamp' => 3000000,
    ]);
    $handler->create([
      'type' => 'processing',
      'title' => 'Processing',
      'message' => 'M',
      'timestamp' => 2000000,
    ]);

    $results = $handler->getRecent(1);

    // Processing first.
    self::assertSame('processing', $results[0]['type']);
    // Then by timestamp descending.
    self::assertSame('New info', $results[1]['title']);
    self::assertSame('Old info', $results[2]['title']);
  }

  public function testGetRecentIncludesHasReadState(): void {
    $handler = $this->handler();
    $n1 = $handler->create([
      'type' => 'info',
      'title' => 'Read',
      'message' => 'M',
    ]);
    $n2 = $handler->create([
      'type' => 'info',
      'title' => 'Unread',
      'message' => 'M',
    ]);

    $handler->markRead(1, [$n1['id']]);

    $results = $handler->getRecent(1);
    $by_id = [];
    foreach ($results as $n) {
      $by_id[$n['id']] = $n;
    }

    self::assertTrue($by_id[$n1['id']]['hasRead']);
    self::assertFalse($by_id[$n2['id']]['hasRead']);
  }

  public function testGetRecentHasReadIsPerUser(): void {
    $handler = $this->handler();
    $n = $handler->create([
      'type' => 'info',
      'title' => 'Shared',
      'message' => 'M',
    ]);

    $handler->markRead(1, [$n['id']]);

    $user1_results = $handler->getRecent(1);
    $user2_results = $handler->getRecent(2);

    self::assertTrue($user1_results[0]['hasRead']);
    self::assertFalse($user2_results[0]['hasRead']);
  }

  public function testMarkReadCreatesReadEntries(): void {
    $handler = $this->handler();
    $n1 = $handler->create(['type' => 'info', 'title' => 'T', 'message' => 'M']);
    $n2 = $handler->create(['type' => 'info', 'title' => 'T', 'message' => 'M']);

    $handler->markRead(1, [$n1['id'], $n2['id']]);

    $count = (int) $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_READ_TABLE, 'r')
      ->condition('uid', 1)
      ->countQuery()
      ->execute()
      ?->fetchField();
    self::assertSame(2, $count);
  }

  public function testMarkAllReadAcrossTypes(): void {
    $handler = $this->handler();

    $n1 = $handler->create(['type' => 'info', 'title' => 'Info', 'message' => 'M']);
    $n2 = $handler->create(['type' => 'warning', 'title' => 'Warning', 'message' => 'M']);
    $n3 = $handler->create(['type' => 'error', 'title' => 'Error', 'message' => 'M']);
    $n4 = $handler->create(['type' => 'success', 'title' => 'Success', 'message' => 'M']);
    $n5 = $handler->create(['type' => 'processing', 'title' => 'Processing', 'message' => 'M']);

    $all_ids = [$n1['id'], $n2['id'], $n3['id'], $n4['id'], $n5['id']];
    $handler->markRead(1, $all_ids);

    $results = $handler->getRecent(1);
    self::assertCount(5, $results);
    foreach ($results as $n) {
      self::assertTrue($n['hasRead'], \sprintf(
        'Notification "%s" (type: %s) should be marked as read.',
        $n['title'],
        $n['type'],
      ));
    }
  }

  public function testMarkAllReadDoesNotAffectOtherUsers(): void {
    $handler = $this->handler();

    $n1 = $handler->create(['type' => 'info', 'title' => 'A', 'message' => 'M']);
    $n2 = $handler->create(['type' => 'error', 'title' => 'B', 'message' => 'M']);

    // Mark all as read for user 1.
    $handler->markRead(1, [$n1['id'], $n2['id']]);

    // User 1 sees all read.
    foreach ($handler->getRecent(1) as $n) {
      self::assertTrue($n['hasRead']);
    }

    // User 2 sees all unread.
    foreach ($handler->getRecent(2) as $n) {
      self::assertFalse($n['hasRead']);
    }
  }

  public function testMarkReadIdempotent(): void {
    $handler = $this->handler();
    $n = $handler->create(['type' => 'info', 'title' => 'T', 'message' => 'M']);

    // Calling twice should not error.
    $handler->markRead(1, [$n['id']]);
    $handler->markRead(1, [$n['id']]);

    $count = (int) $this->container->get('database')
      ->select(CanvasNotificationHandler::NOTIFICATION_READ_TABLE, 'r')
      ->condition('uid', 1)
      ->condition('notification_id', $n['id'])
      ->countQuery()
      ->execute()
      ?->fetchField();
    self::assertSame(1, $count);
  }

}
