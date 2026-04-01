<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;

/**
 * Handles CRUD operations for Canvas notifications.
 */
final class CanvasNotificationHandler {

  const string NOTIFICATION_TABLE = 'canvas_notification';
  const string NOTIFICATION_READ_TABLE = 'canvas_notification_read';
  const int DEFAULT_LIMIT = 25;

  /**
   * Types that trigger deletion of existing notifications with the same key.
   */
  private const array KEY_REPLACE_TYPES = ['processing', 'error', 'warning'];
  private const int PROCESSING_TIMEOUT_MS = 1800000;
  private const int RETENTION_MS = 2592000000;

  public function __construct(
    private readonly Connection $database,
    private readonly UuidInterface $uuid,
  ) {}

  /**
   * Creates a new notification.
   *
   * @param array{type: string, title: string, message: string, key?: string|null, actions?: array<mixed>|null, timestamp?: int} $notification
   *   The notification data. Must NOT contain 'id'.
   *
   * @return array{id: string, type: string, key: string|null, title: string, message: string, timestamp: int, actions: array<mixed>|null}
   *   The created notification with generated 'id'.
   *
   * @throws \InvalidArgumentException
   *   If 'id' is provided or required fields are missing.
   */
  public function create(array $notification): array {
    if (\array_key_exists('id', $notification)) {
      throw new \InvalidArgumentException('Callers must not provide an "id" — it is always auto-generated.');
    }

    foreach (['type', 'title', 'message'] as $field) {
      if (empty($notification[$field])) {
        throw new \InvalidArgumentException(\sprintf('The "%s" field is required.', $field));
      }
    }

    $notification['id'] = $this->uuid->generate();
    if (!isset($notification['timestamp'])) {
      $notification['timestamp'] = (int) (microtime(TRUE) * 1000);
    }

    // If key is set, delete existing processing/error/warning notifications
    // with the same key. This handles state transitions (e.g. processing ->
    // success) and retry flows (e.g. error -> processing).
    if (!empty($notification['key'])) {
      $this->database->delete(self::NOTIFICATION_TABLE)
        ->condition('key', $notification['key'])
        ->condition('type', self::KEY_REPLACE_TYPES, 'IN')
        ->execute();
    }

    // JSON-encode actions if present.
    $actions = $notification['actions'] ?? NULL;
    $db_fields = [
      'id' => $notification['id'],
      'type' => $notification['type'],
      'key' => $notification['key'] ?? NULL,
      'title' => $notification['title'],
      'message' => $notification['message'],
      'timestamp' => $notification['timestamp'],
      'actions' => $actions !== NULL ? Json::encode($actions) : NULL,
    ];

    $this->database->insert(self::NOTIFICATION_TABLE)
      ->fields($db_fields)
      ->execute();

    return [
      'id' => $notification['id'],
      'type' => $notification['type'],
      'key' => $notification['key'] ?? NULL,
      'title' => $notification['title'],
      'message' => $notification['message'],
      'timestamp' => $notification['timestamp'],
      'actions' => $actions,
    ];
  }

  /**
   * Returns recent notifications for a user.
   *
   * All processing notifications are always included (no limit). Non-processing
   * notifications are limited to $limit, sorted by timestamp descending.
   * Processing notifications appear first, then non-processing by timestamp.
   *
   * @param int $uid
   *   The user ID.
   * @param int $limit
   *   Maximum number of non-processing notifications to return.
   *
   * @return array
   *   Array of notification arrays, each with a 'hasRead' boolean.
   */
  public function getRecent(int $uid, int $limit = self::DEFAULT_LIMIT): array {
    $n = self::NOTIFICATION_TABLE;
    $r = self::NOTIFICATION_READ_TABLE;

    $row_mapper = function (array $row): array {
      return [
        'id' => $row['id'],
        'type' => $row['type'],
        'key' => $row['key'],
        'title' => $row['title'],
        'message' => $row['message'],
        'timestamp' => (int) $row['timestamp'],
        'actions' => $row['actions'] !== NULL ? Json::decode($row['actions']) : NULL,
        'hasRead' => (bool) $row['has_read'],
      ];
    };

    $build_query = function (string $type_operator, string $type_value) use ($n, $r, $uid): SelectInterface {
      $query = $this->database->select($n, 'n');
      $query->fields('n');
      $query->leftJoin($r, 'r', 'r.notification_id = n.id AND r.uid = :uid', [':uid' => $uid]);
      $query->addExpression('CASE WHEN r.uid IS NOT NULL THEN 1 ELSE 0 END', 'has_read');
      $query->condition('n.type', $type_value, $type_operator);
      $query->orderBy('n.timestamp', 'DESC');
      return $query;
    };

    // Two separate queries: all processing (unlimited) +
    // non-processing (limited).
    /** @var array<int, array<string, mixed>> $notifications */
    $notifications = [];
    $result = $build_query('=', 'processing')->execute();
    if ($result !== NULL) {
      foreach ($result->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $notifications[] = $row_mapper($row);
      }
    }
    $non_processing_query = $build_query('<>', 'processing');
    $non_processing_query->range(0, $limit);
    $result = $non_processing_query->execute();
    if ($result !== NULL) {
      foreach ($result->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $notifications[] = $row_mapper($row);
      }
    }

    // Sort: processing first, then by timestamp descending.
    usort($notifications, function (array $a, array $b): int {
      $a_processing = $a['type'] === 'processing' ? 0 : 1;
      $b_processing = $b['type'] === 'processing' ? 0 : 1;
      if ($a_processing !== $b_processing) {
        return $a_processing - $b_processing;
      }
      return $b['timestamp'] - $a['timestamp'];
    });

    return $notifications;
  }

  /**
   * Marks notifications as read for a user.
   *
   * Uses MERGE/upsert to be idempotent.
   *
   * @param int $uid
   *   The user ID.
   * @param array $notification_ids
   *   Array of notification ID strings.
   */
  public function markRead(int $uid, array $notification_ids): void {
    $now = (int) (microtime(TRUE) * 1000);
    foreach ($notification_ids as $notification_id) {
      $this->database->merge(self::NOTIFICATION_READ_TABLE)
        ->keys([
          'uid' => $uid,
          'notification_id' => $notification_id,
        ])
        ->fields([
          'timestamp' => $now,
        ])
        ->execute();
    }
  }

  /**
   * Purges stale processing notifications and replaces them with errors.
   *
   * Processing notifications older than 30 minutes are deleted. For each,
   * an error notification is created with the same key and message.
   */
  public function purgeStaleProcessing(): void {
    $threshold = (int) (microtime(TRUE) * 1000) - self::PROCESSING_TIMEOUT_MS;

    // Select stale processing notifications before deleting them.
    $statement = $this->database->select(self::NOTIFICATION_TABLE, 'n')
      ->fields('n', ['key', 'message'])
      ->condition('type', 'processing')
      ->condition('timestamp', $threshold, '<')
      ->execute();
    \assert($statement instanceof StatementInterface);
    $stale = $statement->fetchAll(\PDO::FETCH_ASSOC);

    // Create replacement error notifications.
    foreach ($stale as $row) {
      $this->create([
        'type' => 'error',
        'key' => $row['key'],
        'title' => 'Operation timed out',
        'message' => $row['message'],
      ]);
    }

    // Delete all stale processing in a single query.
    $this->database->delete(self::NOTIFICATION_TABLE)
      ->condition('type', 'processing')
      ->condition('timestamp', $threshold, '<')
      ->execute();
  }

  /**
   * Deletes notifications and read entries older than 30 days.
   *
   * Notifications are deleted first; read entries are only deleted after
   * that succeeds. Both deletes use the same pre-computed cutoff.
   */
  public function deleteExpired(): void {
    $cutoff = (int) (microtime(TRUE) * 1000) - self::RETENTION_MS;
    $transaction = $this->database->startTransaction();
    try {
      $this->database->delete(self::NOTIFICATION_TABLE)
        ->condition('timestamp', $cutoff, '<')
        ->execute();
      $this->database->delete(self::NOTIFICATION_READ_TABLE)
        ->condition('timestamp', $cutoff, '<')
        ->execute();
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

}
