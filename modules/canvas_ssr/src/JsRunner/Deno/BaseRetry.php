<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner\Deno;

/**
 * The record of a base cache build a request gave up on.
 *
 * It's kept until the base cache is built.
 *
 * A request waits the medium time limit for a base cache. If it isn't built
 * by then, the request renders CSR and records it here. Until the base
 * cache is built, renders of that base don't try again: they render CSR at
 * once, and the build is retried after a response is sent, with the long
 * limit. A retry that fails is tried again after a backoff. A render of a
 * new base tries it as usual.
 *
 * It's one file in the OS user's base caches directory, so it's per host,
 * like the base caches. Checking for it costs one stat, so it can be
 * checked after every response, including page cache hits.
 *
 * @phpstan-type Record array{base: string, since: int, due: int, interval: int, error: string}
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner::buildPendingBase()
 */
final class BaseRetry {

  /**
   * The file's name, in the base caches directory.
   */
  public const FILE = 'retry.json';

  /**
   * How long after a failed retry the next one is, at first, in seconds.
   */
  public const FIRST_INTERVAL = 600;

  /**
   * The longest interval between retries, in seconds.
   *
   * Each failed retry doubles it, up to this.
   */
  public const LONGEST_INTERVAL = 3600;

  /**
   * Reads the record, if there is one.
   *
   * @param string $directory
   *   The base caches directory.
   *
   * @return Record|null
   *   The record, or NULL when no build is waiting for a retry.
   */
  public static function read(string $directory): ?array {
    $json = @\file_get_contents($directory . '/' . self::FILE);
    if ($json === FALSE) {
      return NULL;
    }
    $record = \json_decode($json, TRUE);
    if (!\is_array($record)
      || !\is_string($record['base'] ?? NULL)
      || !\is_int($record['since'] ?? NULL)
      || !\is_int($record['due'] ?? NULL)
      || !\is_int($record['interval'] ?? NULL)
      || !\is_string($record['error'] ?? NULL)) {
      // Unreadable, perhaps written part way. Retry now, which rewrites it.
      return ['base' => '', 'since' => \time(), 'due' => 0, 'interval' => 0, 'error' => ''];
    }

    /** @var Record $record */
    return $record;
  }

  /**
   * Says whether a recorded build should be retried now.
   *
   * It's due once its backoff is over, or at once if the site's base
   * changed since, since the new base hasn't failed.
   *
   * @param Record $record
   *   The record.
   * @param string $base_id
   *   The id of the site's base now.
   *
   * @return bool
   *   TRUE to retry now.
   */
  public static function isDue(array $record, string $base_id): bool {
    return $record['due'] <= \time() || $record['base'] !== $base_id;
  }

  /**
   * Records a build a request gave up on.
   *
   * It's then retried after the response.
   *
   * A record for the same base keeps when it started failing.
   *
   * @param string $directory
   *   The base caches directory.
   * @param string $base_id
   *   The base's id.
   * @param string $error
   *   Why the request gave up.
   */
  public static function schedule(string $directory, string $base_id, string $error): void {
    $record = self::read($directory);
    $same_base = $record !== NULL && $record['base'] === $base_id;
    self::write($directory, [
      'base' => $base_id,
      'since' => $same_base ? $record['since'] : \time(),
      'due' => \time(),
      'interval' => $same_base ? $record['interval'] : 0,
      'error' => $error,
    ]);
  }

  /**
   * Records a retry that failed, backing off before the next.
   *
   * @param string $directory
   *   The base caches directory.
   * @param string $base_id
   *   The base's id.
   * @param string $error
   *   Why it failed.
   *
   * @return Record
   *   The record written.
   */
  public static function failed(string $directory, string $base_id, string $error): array {
    $record = self::read($directory);
    $same_base = $record !== NULL && $record['base'] === $base_id;
    $interval = $same_base && $record['interval'] > 0
      ? \min($record['interval'] * 2, self::LONGEST_INTERVAL)
      : self::FIRST_INTERVAL;
    $record = [
      'base' => $base_id,
      'since' => $same_base ? $record['since'] : \time(),
      'due' => \time() + $interval,
      'interval' => $interval,
      'error' => $error,
    ];
    self::write($directory, $record);

    return $record;
  }

  /**
   * Deletes the record, once a base cache is built.
   *
   * @param string $directory
   *   The base caches directory.
   */
  public static function clear(string $directory): void {
    @\unlink($directory . '/' . self::FILE);
  }

  /**
   * Writes the record, replacing any other in one step.
   *
   * @param string $directory
   *   The base caches directory.
   * @param Record $record
   *   The record.
   */
  private static function write(string $directory, array $record): void {
    $temporary = \sprintf('%s/%s.%s.tmp', $directory, self::FILE, \uniqid('', TRUE));
    if (@\file_put_contents($temporary, \json_encode($record, JSON_THROW_ON_ERROR)) === FALSE
      || !@\rename($temporary, $directory . '/' . self::FILE)) {
      @\unlink($temporary);
    }
  }

}
