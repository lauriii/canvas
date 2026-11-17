<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Composer\Autoload\ClassLoader;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Deno runner says what a host's PHP settings keep it from doing.
 *
 * Shared hosts often disable proc_open() or set open_basedir, neither of
 * which a running PHP can change, so each case runs PHP with them.
 */
#[CoversClass(DenoRunner::class)]
#[Group('canvas_ssr')]
final class HostSupportTest extends UnitTestCase {

  use DenoBinaryTrait;

  /**
   * The PHP each case runs. It prints what the runner reports, as JSON.
   */
  private const SCRIPT = <<<'PHP'
    <?php
    [, $autoload, $module_src, $directory] = $argv;
    $loader = require $autoload;
    $loader->addPsr4('Drupal\\canvas_ssr\\', $module_src);
    $runner = new Drupal\canvas_ssr\JsRunner\Deno\DenoRunner('/bin/true', [], "$directory/base-caches", "$directory/runner-caches");
    echo json_encode([
      'available' => $runner->isAvailable(),
      'reason' => $runner->unavailableReason(),
      'limitations' => $runner->limitations(),
    ]);
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
    $this->directory = self::makeTemporaryDirectory('host-support');
    \file_put_contents($this->directory . '/script.php', self::SCRIPT);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::removeTree($this->directory);
    parent::tearDown();
  }

  /**
   * Tests the runner is unavailable, and says why, without proc_open().
   */
  public function testDisabledProcessFunctionsMakeItUnavailable(): void {
    $report = $this->report(['disable_functions' => 'proc_open,proc_terminate']);

    self::assertFalse($report['available']);
    self::assertStringContainsString('proc_open(), proc_terminate() disabled', (string) $report['reason']);
  }

  /**
   * Tests the runner names the limits it can't enforce without /proc.
   */
  public function testUnreadableProcIsALimitation(): void {
    if (!\is_readable('/proc/self/stat')) {
      self::markTestSkipped('This system has no /proc to keep PHP from.');
    }
    $root = \dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 3);
    $binary = \dirname((string) \realpath('/bin/true'));
    $report = $this->report(['open_basedir' => $root . ':' . \realpath($this->directory) . ':' . \sys_get_temp_dir() . ':' . $binary]);

    self::assertCount(1, $report['limitations']);
    self::assertStringContainsString('can\'t read /proc', $report['limitations'][0]);
    self::assertStringContainsString('256 MiB', $report['limitations'][0]);
  }

  /**
   * Tests a host with /proc readable has no limitations.
   */
  public function testReadableProcIsNoLimitation(): void {
    if (!\is_readable('/proc/self/stat')) {
      self::markTestSkipped('This system has no /proc.');
    }

    self::assertSame([], $this->report([])['limitations']);
  }

  /**
   * Runs the script with PHP settings, and returns what it printed.
   *
   * @param array<string, string> $settings
   *   The PHP settings.
   *
   * @return array<string, mixed>
   *   What the runner reported: whether it's available, the reason if it
   *   isn't, and its limitations.
   */
  private function report(array $settings): array {
    // A warning on stdout would spoil the JSON.
    $command = [\PHP_BINARY, '-d', 'display_errors=stderr'];
    foreach ($settings as $name => $value) {
      $command[] = '-d';
      $command[] = $name . '=' . $value;
    }
    $autoload = \dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';
    \array_push($command, $this->directory . '/script.php', $autoload, \dirname(__DIR__, 5) . '/src', $this->directory);
    $pipes = [];
    $php = \proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    self::assertIsResource($php);
    $output = (string) \stream_get_contents($pipes[1]);
    $errors = (string) \stream_get_contents($pipes[2]);
    \proc_close($php);

    $report = \json_decode($output, TRUE);
    self::assertIsArray($report, $output . $errors);

    return $report;
  }

}
