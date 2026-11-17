<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Traits;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;

/**
 * Warms Deno up, once per PHP process, before tests run it under limits.
 *
 * On a host that just started, Deno's first runs can take seconds while
 * its binary is read from disk. A test whose runner has time limits, even
 * the production ones, would then fail on whichever test runs first. Each
 * run reads more of the binary, so this goes through what the tests will:
 * a base build, then a render, with a minute to spare. Once one process has
 * read the binary, later ones find it in memory, so this runs once per
 * process.
 *
 * @see \Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait
 * @see \Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase::warmUpDenoUnderTest()
 */
final class DenoWarmUp {

  /**
   * Whether this process has warmed Deno up.
   */
  private static bool $done = FALSE;

  /**
   * Builds a base and renders from it once, with time to spare.
   *
   * Does nothing without a Deno binary, so tests skip as they would.
   */
  public static function run(): void {
    $binary = DenoRunner::defaultBinary();
    if (self::$done || !\is_executable($binary)) {
      return;
    }
    self::$done = TRUE;
    $directory = \sprintf('%s/canvas-ssr-warm-up-%s', \sys_get_temp_dir(), \bin2hex(\random_bytes(8)));
    // Not group-writable under any umask, or the runner wouldn't use it.
    \mkdir($directory, 0755);
    try {
      $runner = new DenoRunner($binary, ['short' => 15000, 'medium' => 30000, 'long' => 60000], $directory . '/base-caches', $directory . '/runner-caches');
      $url = '/warm-up.js';
      $source = 'export default () => "warm";';
      if ($runner->isAvailable() && $runner->useBase('warm-up', static fn(): array => ['warm-up', ['modules' => [$url => $source], 'preload' => [$url], 'importMap' => []]]) !== FALSE) {
        $runner->addModule($url, $source);
        $runner->invoke($url, '""');
      }
      $runner->reset();
    }
    finally {
      self::remove($directory);
    }
  }

  /**
   * Removes a directory tree without following links.
   *
   * @param string $path
   *   The directory, file or link to remove.
   */
  private static function remove(string $path): void {
    if (\is_dir($path) && !\is_link($path)) {
      $names = \scandir($path);
      foreach ($names === FALSE ? [] : $names as $name) {
        if ($name !== '.' && $name !== '..') {
          self::remove($path . '/' . $name);
        }
      }
      \rmdir($path);

      return;
    }
    @\unlink($path);
  }

}
