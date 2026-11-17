<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr;

use Psr\Log\AbstractLogger;

/**
 * Collects what's logged by level, with placeholders replaced.
 */
final class CollectingLogger extends AbstractLogger {

  /**
   * The messages logged, by level.
   *
   * @var array<string, list<string>>
   */
  public array $messages = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $replacements = [];
    foreach ($context as $name => $value) {
      if (\is_scalar($value) || $value instanceof \Stringable) {
        $replacements['{' . $name . '}'] = (string) $value;
      }
    }
    $this->messages[(string) $level][] = \strtr((string) $message, $replacements);
  }

  /**
   * Returns the messages logged at a level.
   *
   * @return list<string>
   *   The messages.
   */
  public function at(string $level): array {
    return $this->messages[$level] ?? [];
  }

}
