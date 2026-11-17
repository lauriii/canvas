<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\Log;

use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Logs each message at most once an interval on each host.
 *
 * For a problem that recurs on every request until someone fixes it, such
 * as a directory the Deno runner can't use. How often a request starts a PHP
 * process depends on how PHP is run, so a limit kept in PHP's memory would
 * log once per request on some platforms and rarely on others. So this
 * keeps its limit in a key-value store that every PHP process of the site
 * shares, keyed by host, since each host has its own directories.
 *
 * Each distinct message has its own limit, so a new problem is logged
 * right away. The interval depends on the level, so a standing condition
 * can repeat rarely while each occurrence of an event stays visible.
 */
final class ThrottledLogger extends AbstractLogger {

  /**
   * Constructs a ThrottledLogger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger to log to.
   * @param \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $logged
   *   Records which messages were logged in the interval.
   * @param array<string, int> $intervalSeconds
   *   How long a message isn't logged again on a host, in seconds, by
   *   level. Levels not listed aren't limited.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly KeyValueStoreExpirableInterface $logged,
    private readonly array $intervalSeconds,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $interval = $this->intervalSeconds[(string) $level] ?? NULL;
    if ($interval === NULL) {
      $this->logger->log($level, $message, $context);
      return;
    }
    $replacements = [];
    foreach ($context as $name => $value) {
      if (\is_scalar($value) || $value instanceof \Stringable) {
        $replacements['{' . $name . '}'] = (string) $value;
      }
    }
    $key = \hash('sha256', \implode("\0", [
      (string) \gethostname(),
      (string) $level,
      \strtr((string) $message, $replacements),
    ]));
    try {
      // Atomic, so concurrent requests log it once between them.
      $first = $this->logged->setWithExpireIfNotExists($key, TRUE, $interval);
    }
    catch (\Exception) {
      // Logging must not fail the caller, and too much beats nothing.
      $first = TRUE;
    }
    if ($first) {
      $this->logger->log($level, $message, $context);
    }
  }

}
