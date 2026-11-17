<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr;

use Drupal\canvas_ssr\JsRunner\RunnerInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Keeps what each web server reports about SSR, for the status report.
 *
 * Each host has its own runner and cache directories, so SSR can be off, or
 * its base unbuilt, on one host and fine on another. The status report is
 * served by just one of them. So each host records its problems in a store
 * every host shares, and the report lists them all.
 *
 * A record expires unless its host renews it, so a host that's gone, like
 * an autoscaled container, drops off by itself. It's written only when a
 * host finds a problem, never when all is well, so it costs nothing on a
 * healthy site.
 *
 * @phpstan-type Report array{off?: array{reason: string, at: int}, limits?: array{limitations: list<string>, at: int}, base?: array{since: int, due: int, error: string}}
 */
final class HostStatus {

  /**
   * How long a record of no runner lasts unless renewed, in seconds.
   */
  private const OFF_EXPIRY = 3600;

  /**
   * How often a host renews its record of no runner, at most, in seconds.
   */
  private const OFF_RENEWAL = 1800;

  /**
   * How long a record of an unbuilt base lasts unless renewed, in seconds.
   *
   * Longer than a runner waits between tries, each of which renews it.
   */
  private const BASE_EXPIRY = 7200;

  /**
   * Whether this process has recorded no runner, so it's done once.
   */
  private bool $offRecorded = FALSE;

  /**
   * Whether this process has recorded the runner's limitations.
   */
  private bool $limitsRecorded = FALSE;

  /**
   * Constructs a HostStatus.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $reports
   *   Every host's records, keyed by kind and host.
   * @param \Drupal\canvas_ssr\JsRunner\RunnerInterface $runner
   *   The runner renders use, which says why it's unavailable.
   */
  public function __construct(
    private readonly KeyValueStoreExpirableInterface $reports,
    private readonly RunnerInterface $runner,
  ) {}

  /**
   * Returns this host's name, as its records are keyed.
   */
  public static function host(): string {
    $host = \gethostname();

    return $host === FALSE || $host === '' ? 'unknown' : $host;
  }

  /**
   * Records that no runner is available on this host.
   *
   * Called when an island falls back to CSR for want of one, so at most
   * once per request. It reads the record first, and writes only if it's
   * missing, says something else, or is due for renewal.
   */
  public function recordRunnerUnavailable(): void {
    if ($this->offRecorded) {
      return;
    }
    $this->offRecorded = TRUE;
    $reason = $this->runner->unavailableReason() ?? '';
    $key = 'off:' . self::host();
    try {
      $current = $this->reports->get($key);
      if (\is_array($current) && ($current['reason'] ?? NULL) === $reason && \time() - (int) ($current['at'] ?? 0) < self::OFF_RENEWAL) {
        return;
      }
      $this->reports->setWithExpire($key, ['reason' => $reason, 'at' => \time()], self::OFF_EXPIRY);
    }
    catch (\Exception) {
      // The status report is for humans; a render mustn't fail for it.
    }
  }

  /**
   * Records what this host's runner can't enforce, if anything.
   *
   * Called once a render finds a runner available, so at most once per
   * request. A host whose runner enforces everything writes nothing. Like
   * the record of no runner, it's written when missing, changed, or due
   * for renewal.
   */
  public function recordLimitations(): void {
    if ($this->limitsRecorded) {
      return;
    }
    $this->limitsRecorded = TRUE;
    $limitations = $this->runner->limitations();
    if ($limitations === []) {
      return;
    }
    $key = 'limits:' . self::host();
    try {
      $current = $this->reports->get($key);
      if (\is_array($current) && ($current['limitations'] ?? NULL) === $limitations && \time() - (int) ($current['at'] ?? 0) < self::OFF_RENEWAL) {
        return;
      }
      $this->reports->setWithExpire($key, ['limitations' => $limitations, 'at' => \time()], self::OFF_EXPIRY);
    }
    catch (\Exception) {
      // The status report is for humans; a render mustn't fail for it.
    }
  }

  /**
   * Records that this host's runner still can't build the base.
   *
   * @param array{since: int, due: int, error: string} $record
   *   Since when it's been failing, when it's tried next, and why.
   */
  public function recordBaseFailing(array $record): void {
    try {
      $this->reports->setWithExpire('base:' . self::host(), $record, self::BASE_EXPIRY);
    }
    catch (\Exception) {
    }
  }

  /**
   * Records that this host's runner built the base.
   */
  public function recordBaseBuilt(): void {
    try {
      $this->reports->delete('base:' . self::host());
    }
    catch (\Exception) {
    }
  }

  /**
   * Returns every host's records, by host.
   *
   * @return array<string, Report>
   *   The records, sorted by host.
   */
  public function reports(): array {
    $reports = [];
    foreach ($this->reports->getAll() as $key => $value) {
      [$kind, $host] = \explode(':', (string) $key, 2) + [1 => ''];
      if (\in_array($kind, ['off', 'limits', 'base'], TRUE) && \is_array($value)) {
        $reports[$host][$kind] = $value;
      }
    }
    \ksort($reports);

    /** @var array<string, Report> $reports */
    return $reports;
  }

}
