<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Finds the Deno binary, and gives a test a directory of its own.
 *
 * The Deno tests construct DenoRunner directly, with the binary the module
 * README says to install and with cache roots under a directory the test
 * removes afterwards.
 */
trait DenoBinaryTrait {

  /**
   * The umask before the test, which it gets back afterwards.
   */
  private int $umaskBefore;

  /**
   * Sets the umask to 0022, before setUp() makes directories.
   *
   * The Deno runner doesn't use a root that a group may write to, and a umask
   * such as 0002, which CI may set, makes each directory a test makes so.
   */
  #[Before(100)]
  public function setUmask(): void {
    $this->umaskBefore = \umask(0022);
  }

  /**
   * Restores the umask from before the test.
   */
  #[After]
  public function restoreUmask(): void {
    \umask($this->umaskBefore);
  }

  /**
   * Returns the path to the Deno binary.
   */
  private static function denoBinary(): string {
    return DenoRunner::defaultBinary();
  }

  /**
   * Skips the test if the Deno binary isn't installed.
   */
  private static function skipWithoutDeno(): void {
    if (!\is_executable(self::denoBinary())) {
      self::markTestSkipped('No Deno binary is installed at vendor/bin/deno.');
    }
  }

  /**
   * Makes a directory for this test's files.
   *
   * @param string $purpose
   *   What it's for, as part of its name.
   *
   * @return string
   *   The directory.
   */
  private static function makeTemporaryDirectory(string $purpose): string {
    $directory = \sprintf('%s/canvas-ssr-%s-%s', \sys_get_temp_dir(), $purpose, \bin2hex(\random_bytes(8)));
    \mkdir($directory);

    return $directory;
  }

  /**
   * Returns the paths that match a pattern.
   *
   * @param string $pattern
   *   The pattern, as glob() takes it.
   * @param int $flags
   *   The flags, as glob() takes them.
   *
   * @return list<string>
   *   The paths, or none if glob() fails.
   */
  private static function paths(string $pattern, int $flags = 0): array {
    $paths = \glob($pattern, $flags);

    return $paths === FALSE ? [] : $paths;
  }

  /**
   * Removes a directory tree without following links.
   *
   * @param string $path
   *   The directory, file or link to remove.
   */
  private static function removeTree(string $path): void {
    if (\is_dir($path) && !\is_link($path)) {
      $names = \scandir($path);
      foreach ($names === FALSE ? [] : $names as $name) {
        if ($name !== '.' && $name !== '..') {
          self::removeTree($path . '/' . $name);
        }
      }
      \rmdir($path);

      return;
    }
    @\unlink($path);
  }

}
