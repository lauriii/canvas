<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Composer\Autoload\ClassLoader;
use Drupal\canvas_ssr\JsRunner\Deno\DenoProcess;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\Deno\RunnerCache;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * Tests no Deno process outlives the PHP process that started it.
 *
 * Each case runs a PHP process that renders, then dies as PHP can: killed,
 * or with a fatal error, which runs no destructors. A Deno process left
 * running JS meets its CPU time limit, but that's long, so here it's set
 * longer than the test waits.
 */
#[CoversClass(DenoProcess::class)]
#[CoversClass(DenoRunner::class)]
#[CoversClass(RunnerCache::class)]
#[Group('canvas_ssr')]
#[Large]
final class ProcessLifetimeTest extends UnitTestCase {

  use DenoBinaryTrait;

  /**
   * The PHP each case runs. It prints the Deno process's ID, then dies.
   */
  private const SCRIPT = <<<'PHP'
    <?php
    [, $autoload, $module_src, $directory, $binary, $how] = $argv;
    $loader = require $autoload;
    $loader->addPsr4('Drupal\\canvas_ssr\\', $module_src);
    $runner = new Drupal\canvas_ssr\JsRunner\Deno\DenoRunner($binary, ['short' => 15000, 'medium' => 30000, 'long' => 60000], "$directory/base-caches", "$directory/runner-caches");
    // Everything is registered first, since a module added later starts
    // another process.
    $die = FALSE;
    $runner->bindFunction('host', static function () use (&$die): string {
      if ($die) {
        exec('kill -9 ' . getmypid());
      }
      return '';
    }, '() => ""', '(answer) => answer');
    $runner->addModule('/ok.js', 'export default () => "ok";');
    $runner->addModule('/waits.js', 'export default () => host();');
    $runner->addModule('/spins.js', 'export default () => { while (true); };');
    $runner->invoke('/ok.js', '""');
    $children = glob('/proc/' . getmypid() . '/task/*/children') ?: [];
    echo trim((string) file_get_contents($children[0] ?? '/dev/null')), "\n";
    flush();
    switch ($how) {
      case 'killed while idle':
        exec('kill -9 ' . getmypid());
        break;

      case 'killed while Deno waits on PHP':
        $die = TRUE;
        $runner->invoke('/waits.js', '""');
        break;

      case 'a fatal error while Deno spins':
        pcntl_async_signals(TRUE);
        pcntl_signal(SIGALRM, static function (): void {
          ini_set('memory_limit', '32M');
          str_repeat('x', 256 * 1024 * 1024);
        });
        pcntl_alarm(1);
        $runner->invoke('/spins.js', '""');
        break;
    }
    PHP;

  /**
   * Holds the script and the caches.
   */
  private string $directory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    self::skipWithoutDeno();
    if (!\is_dir('/proc/self/task')) {
      self::markTestSkipped('This system lists a process\'s children only in /proc.');
    }
    $this->directory = self::makeTemporaryDirectory('lifetime');
    \file_put_contents($this->directory . '/script.php', self::SCRIPT);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->directory)) {
      self::removeTree($this->directory);
    }
    parent::tearDown();
  }

  /**
   * Tests the Deno process ends soon after PHP does, however PHP ends.
   *
   * @param string $how
   *   How PHP dies.
   */
  #[DataProvider('deaths')]
  public function testDenoEndsWithPhp(string $how): void {
    if ($how === 'a fatal error while Deno spins' && !\function_exists('pcntl_alarm')) {
      self::markTestSkipped('PHP has no pcntl here.');
    }
    $autoload = \dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
    $command = [
      \PHP_BINARY,
      $this->directory . '/script.php',
      $autoload,
      \dirname(__DIR__, 5) . '/src',
      $this->directory,
      self::denoBinary(),
      $how,
    ];
    $pipes = [];
    $php = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    self::assertIsResource($php);
    $pid = (int) \fgets($pipes[1]);
    self::assertGreaterThan(0, $pid, (string) \stream_get_contents($pipes[2]));

    $output = \stream_get_contents($pipes[1]) . \stream_get_contents($pipes[2]);
    $code = \proc_close($php);
    // A fatal error exits with 255, and a kill -9 with 9.
    self::assertSame($how === 'a fatal error while Deno spins' ? 255 : 9, $code, $output);

    $deadline = \microtime(TRUE) + 5;
    while (self::running($pid) && \microtime(TRUE) < $deadline) {
      \usleep(20000);
    }
    $running = self::running($pid);
    if ($running) {
      \exec('kill -9 ' . $pid);
    }
    self::assertFalse($running, "The Deno process outlived PHP, $how.");
    // A fatal error runs shutdown functions, which a kill doesn't.
    if ($how === 'a fatal error while Deno spins') {
      self::assertSame([], self::paths($this->directory . '/runner-caches/drupal-*/canvas/ssr/deno/*'), 'A runner cache outlived PHP.');
    }
  }

  /**
   * Data provider for testDenoEndsWithPhp().
   *
   * @return array<string, array{string}>
   *   How PHP dies, keyed the same.
   */
  public static function deaths(): array {
    $deaths = ['killed while idle', 'killed while Deno waits on PHP', 'a fatal error while Deno spins'];

    return \array_combine($deaths, \array_map(static fn(string $how): array => [$how], $deaths));
  }

  /**
   * Checks whether a process is running, counting a zombie as ended.
   */
  private static function running(int $pid): bool {
    $stat = @\file_get_contents('/proc/' . $pid . '/stat');
    if (!\is_string($stat)) {
      return FALSE;
    }
    $state = \substr($stat, \strrpos($stat, ')') + 2, 1);

    return $state !== 'Z' && $state !== 'X';
  }

}
