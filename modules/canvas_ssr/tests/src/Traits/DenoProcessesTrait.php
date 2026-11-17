<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Traits;

/**
 * Lists the Deno processes this PHP process started.
 *
 * It reads the process's children from /proc on Linux, and asks pgrep
 * elsewhere, as on macOS. Where neither works, the test using it skips.
 */
trait DenoProcessesTrait {

  /**
   * Returns the IDs of this PHP process's Deno children, sorted.
   *
   * @return list<int>
   *   The process IDs.
   */
  private static function denoProcesses(): array {
    $pids = self::denoProcessesFromProc() ?? self::denoProcessesFromPgrep();
    if ($pids === NULL) {
      self::markTestSkipped('This system lists a process\'s children neither in /proc nor through pgrep.');
    }
    \sort($pids);

    return $pids;
  }

  /**
   * Returns the Deno children /proc lists, or NULL without /proc.
   *
   * @return list<int>|null
   *   The process IDs.
   */
  private static function denoProcessesFromProc(): ?array {
    $files = \glob('/proc/' . \getmypid() . '/task/*/children');
    if ($files === FALSE || $files === []) {
      return NULL;
    }
    $pids = [];
    foreach ($files as $file) {
      $children = \preg_split('/\s+/', \trim((string) \file_get_contents($file)), -1, PREG_SPLIT_NO_EMPTY);
      foreach ($children === FALSE ? [] : $children as $pid) {
        if (\trim((string) @\file_get_contents('/proc/' . $pid . '/comm')) === 'deno') {
          $pids[] = (int) $pid;
        }
      }
    }

    return $pids;
  }

  /**
   * Returns the Deno children pgrep finds, or NULL if it can't run.
   *
   * @return list<int>|null
   *   The process IDs.
   */
  private static function denoProcessesFromPgrep(): ?array {
    $output = [];
    $status = 2;
    @\exec('pgrep -x -P ' . \getmypid() . ' deno 2>/dev/null', $output, $status);
    // 1 means it ran and found none.
    return match ($status) {
      0 => \array_map(\intval(...), $output),
      1 => [],
      default => NULL,
    };
  }

}
