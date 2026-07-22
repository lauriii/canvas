<?php

declare(strict_types=1);

namespace Drupal\canvas\Health;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;

/**
 * Persists per-data-item Canvas validation results for incremental reuse.
 *
 * @phpstan-import-type Finding from \Drupal\canvas\Health\Doctor
 * @internal
 * @see docs/adr/0017-data-health-validation-check-based-coverage-with-environment-fingerprinted-incremental-results.md
 */
final class HealthRecords {

  public const string TABLE = 'canvas_health_records';

  public function __construct(
    private readonly Connection $database,
    private readonly EnvironmentInterface $environment,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Whether a result can be reused; always FALSE for a dev checkout.
   *
   * @see \Drupal\canvas\Health\Environment::DEV_VERSION
   */
  public function isFresh(string $key, HealthCheck $check, string $signature): bool {
    if ($this->environment->getDevExtensions() !== []) {
      return FALSE;
    }
    try {
      $count = $this->database->select(self::TABLE, 'r')
        ->condition('r.data_key', $key)
        ->condition('r.check', $check->value)
        ->condition('r.env_fingerprint', $this->environment->getFingerprint())
        ->condition('r.data_fingerprint', $signature)
        ->countQuery()
        ->execute()
        ?->fetchField();
      return (int) $count > 0;
    }
    catch (\Exception $e) {
      $this->rethrowIfTableExists($e);
      return FALSE;
    }
  }

  /**
   * Call isFresh() first: this does not check freshness itself.
   *
   * @return list<Finding>|null
   */
  public function getFindings(string $key, HealthCheck $check): ?array {
    try {
      $encoded = $this->database->select(self::TABLE, 'r')
        ->fields('r', ['findings'])
        ->condition('r.data_key', $key)
        ->condition('r.check', $check->value)
        ->execute()
        ?->fetchField();
      if ($encoded === NULL || $encoded === FALSE) {
        return NULL;
      }
      $decoded = Json::decode((string) $encoded);
      if (!\is_array($decoded)) {
        return [];
      }
      /** @var list<Finding> $decoded */
      return $decoded;
    }
    catch (\Exception $e) {
      $this->rethrowIfTableExists($e);
      return NULL;
    }
  }

  /**
   * @param list<Finding> $findings
   */
  public function record(string $key, HealthCheck $check, string $signature, array $findings): void {
    $has_violations = FALSE;
    foreach ($findings as $finding) {
      if ($finding['violations'] !== []) {
        $has_violations = TRUE;
        break;
      }
    }
    $fields = [
      'data_key' => $key,
      'check' => $check->value,
      'env_fingerprint' => $this->environment->getFingerprint(),
      'data_fingerprint' => $signature,
      'findings' => Json::encode($findings),
      'has_violations' => $has_violations ? 1 : 0,
      'checked_at' => $this->time->getRequestTime(),
    ];
    $try_again = FALSE;
    try {
      $this->database->merge(self::TABLE)
        ->keys(['data_key' => $key, 'check' => $check->value])
        ->fields($fields)
        ->execute();
    }
    catch (\Exception $e) {
      $try_again = $this->ensureTableExists();
      if (!$try_again) {
        throw $e;
      }
    }
    if ($try_again) {
      $this->database->merge(self::TABLE)
        ->keys(['data_key' => $key, 'check' => $check->value])
        ->fields($fields)
        ->execute();
    }
  }

  /**
   * Read-only summary, scoped to the CURRENT environment fingerprint.
   *
   * @param \Drupal\canvas\Health\HealthCheck[] $checks
   *
   * @return array{total_items: int, problem_items: int, last_checked_at: int|null}
   */
  public function getSummary(array $checks = []): array {
    $empty = ['total_items' => 0, 'problem_items' => 0, 'last_checked_at' => NULL];
    $fingerprint = $this->environment->getFingerprint();
    $check_values = \array_map(static fn (HealthCheck $c): string => $c->value, $checks);
    $scope = function (SelectInterface $query) use ($fingerprint, $check_values): SelectInterface {
      $query->condition('r.env_fingerprint', $fingerprint);
      if ($check_values !== []) {
        $query->condition('r.check', $check_values, 'IN');
      }
      return $query;
    };
    try {
      $total = (int) $scope($this->database->select(self::TABLE, 'r'))
        ->countQuery()->execute()?->fetchField();
      if ($total === 0) {
        return $empty;
      }
      $problems = (int) $scope($this->database->select(self::TABLE, 'r'))
        ->condition('r.has_violations', 1)
        ->countQuery()->execute()?->fetchField();
      $last = $scope($this->database->select(self::TABLE, 'r'))
        ->fields('r', ['checked_at'])
        ->orderBy('checked_at', 'DESC')
        ->range(0, 1)
        ->execute()?->fetchField();
      return [
        'total_items' => $total,
        'problem_items' => $problems,
        'last_checked_at' => ($last === NULL || $last === FALSE) ? NULL : (int) $last,
      ];
    }
    catch (\Exception $e) {
      $this->rethrowIfTableExists($e);
      return $empty;
    }
  }

  /**
   * Deletes the stored results of one entity, so no orphan rows outlive it.
   *
   * Matches the entity-level key plus any key extending it with a third
   * segment (revisions, auto-save snapshots); see Doctor::KEY_FORMAT.
   * $check limits the deletion to that check (e.g. only auto-save rows, when
   * an auto-save entry is discarded but the entity itself lives on).
   */
  public function deleteForEntity(EntityInterface $entity, ?HealthCheck $check = NULL): void {
    $key = \sprintf(Doctor::KEY_FORMAT, $entity->getEntityTypeId(), (string) $entity->id());
    try {
      $entity_or_sub_item = $this->database->condition('OR')
        ->condition('data_key', $key)
        ->condition('data_key', $this->database->escapeLike($key) . ':%', 'LIKE');
      $delete = $this->database->delete(self::TABLE)
        ->condition($entity_or_sub_item);
      if ($check !== NULL) {
        $delete->condition('check', $check->value);
      }
      $delete->execute();
    }
    catch (\Exception $e) {
      $this->rethrowIfTableExists($e);
    }
  }

  /**
   * Deletes the stored result of one revision when it alone is deleted.
   */
  public function deleteForRevision(RevisionableInterface $entity): void {
    try {
      $this->database->delete(self::TABLE)
        ->condition('data_key', \sprintf('%s:%s:%s', $entity->getEntityTypeId(), (string) $entity->id(), (string) $entity->getRevisionId()))
        ->condition('check', [HealthCheck::ContentPastRevisions->value, HealthCheck::ContentForwardRevisions->value], 'IN')
        ->execute();
    }
    catch (\Exception $e) {
      $this->rethrowIfTableExists($e);
    }
  }

  /**
   * Discards the stored results of one check, forcing revalidation.
   */
  public function clear(HealthCheck $check): void {
    try {
      $this->database->delete(self::TABLE)
        ->condition('check', $check->value)
        ->execute();
    }
    catch (\Exception $e) {
      $this->rethrowIfTableExists($e);
    }
  }

  private function ensureTableExists(): bool {
    try {
      $this->database->schema()->createTable(self::TABLE, self::schemaDefinition());
    }
    catch (DatabaseException) {
      // Another process already created it.
    }
    catch (\Exception) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Re-throws unless the exception is "table does not exist yet".
   */
  private function rethrowIfTableExists(\Exception $e): void {
    if ($this->database->schema()->tableExists(self::TABLE)) {
      throw $e;
    }
  }

  private static function schemaDefinition(): array {
    return [
      'description' => 'Stores per-data-item Canvas validation results for incremental reuse.',
      'fields' => [
        'data_key' => [
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'description' => 'Key identifying the data item within its check (entity_type:id).',
        ],
        'check' => [
          'type' => 'varchar_ascii',
          'length' => 64,
          'not null' => TRUE,
          // CHECK is a reserved SQL word, but Drupal quotes all identifiers.
          'description' => 'The HealthCheck case this result belongs to.',
        ],
        'env_fingerprint' => [
          'type' => 'varchar_ascii',
          'length' => 64,
          'not null' => TRUE,
          'description' => 'Environment fingerprint at validation time (schema:applied:pending:hash).',
        ],
        'data_fingerprint' => [
          'type' => 'varchar_ascii',
          'length' => 64,
          'not null' => TRUE,
          'description' => 'Data fingerprint (freshness signature): cache-tag checksum or data hash.',
        ],
        'findings' => [
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          'sqlite_type' => 'json',
          'not null' => TRUE,
          'description' => 'JSON-encoded findings array.',
        ],
        'has_violations' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
          'description' => 'Whether any finding has violations; indexable substitute for scanning the findings JSON.',
        ],
        'checked_at' => [
          'type' => 'int',
          'size' => 'big',
          'not null' => TRUE,
          'description' => 'Unix timestamp of when this result was recorded.',
        ],
      ],
      'primary key' => ['data_key', 'check'],
      'indexes' => [
        'idx_check' => ['check'],
        // Serves both the env-scoped total/last queries (leftmost prefix) and
        // the env-scoped problem count via has_violations.
        'idx_env_fingerprint' => ['env_fingerprint', 'has_violations'],
      ],
    ];
  }

}
