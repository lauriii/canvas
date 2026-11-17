<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr;

/**
 * SSR's three time limits, in milliseconds.
 *
 * They come from the canvas_ssr.timeouts container parameter. A site can
 * set any of them in its services.yml, and those it leaves out get
 * DEFAULTS.
 *
 * - Short: what one invoke() may spend running JS. Control messages, which
 *   run no component code, get it too.
 * - Medium: what a request waits, in wall time, for anything slower. That's
 *   parsing a component's compiled JS, building a base, a runner's process
 *   starting, or one invoke() with its bound function calls.
 * - Long: what work after the response is sent may take, such as building
 *   a base a request couldn't wait for. A process that runs JS gets half
 *   again as much CPU time, so the wall-clock limit ends every normal case.
 *
 * Each must be at least twice the one before it, medium twice short and
 * long twice medium. The Deno runner replaces a process once it has spent
 * half its CPU time limit, judged by its CPU time when the last invoke was
 * sent. So up to two invokes' worth goes uncounted, and short, at most a
 * quarter of long, keeps that within the other half.
 *
 * The Deno runner's README says how it applies each.
 */
final class Timeouts {

  /**
   * The defaults, by name.
   */
  public const DEFAULTS = ['short' => 100, 'medium' => 2000, 'long' => 10000];

  /**
   * Constructs a Timeouts.
   *
   * @param int $short
   *   The short limit, in milliseconds.
   * @param int $medium
   *   The medium limit, in milliseconds.
   * @param int $long
   *   The long limit, in milliseconds.
   */
  private function __construct(
    public readonly int $short,
    public readonly int $medium,
    public readonly int $long,
  ) {}

  /**
   * Makes one from an array that may leave some limits out.
   *
   * @param array{short?: int, medium?: int, long?: int} $timeouts
   *   The limits to set, by name, in milliseconds. Others get their default.
   *
   * @return self
   *   The limits.
   *
   * @throws \InvalidArgumentException
   *   When a name is unknown, a limit isn't a positive integer, or a limit
   *   is under twice the one before it.
   */
  public static function fromArray(array $timeouts): self {
    $unknown = \array_diff_key($timeouts, self::DEFAULTS);
    if ($unknown !== []) {
      throw new \InvalidArgumentException(\sprintf('Unknown SSR timeouts: %s.', \implode(', ', \array_keys($unknown))));
    }
    $timeouts += self::DEFAULTS;
    foreach ($timeouts as $name => $milliseconds) {
      if (!\is_int($milliseconds) || $milliseconds < 1) {
        throw new \InvalidArgumentException(\sprintf('The SSR %s timeout must be a positive number of milliseconds.', $name));
      }
    }

    if ($timeouts['medium'] < 2 * $timeouts['short'] || $timeouts['long'] < 2 * $timeouts['medium']) {
      throw new \InvalidArgumentException(\sprintf(
        'Each SSR timeout must be at least twice the one before it, medium twice short and long twice medium, but they are %d, %d and %d ms.',
        $timeouts['short'],
        $timeouts['medium'],
        $timeouts['long'],
      ));
    }

    return new self($timeouts['short'], $timeouts['medium'], $timeouts['long']);
  }

  /**
   * Returns the CPU time limit a process that runs JS gets, in seconds.
   *
   * Half again the long limit, since garbage collection threads can make a
   * process use CPU a little faster than wall time passes.
   */
  public function cpuLimitSeconds(): int {
    return (int) \ceil($this->long * 1.5 / 1000);
  }

}
