<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\BaseCaches;
use Drupal\canvas_ssr\JsRunner\Deno\BaseRetry;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;

/**
 * Tests how long a request waits for a base cache, and what happens after.
 *
 * A request waits the medium limit for a build, its own or another
 * process's. A build that takes longer leaves nothing, and is retried with
 * the long limit once the response is sent, then after a backoff. Until a
 * base cache is built, renders don't wait at all.
 *
 * A build is slowed or stopped here by a preload module's top level, as a
 * component's could be.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner::useBase()
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner::buildPendingBase()
 */
#[CoversClass(DenoRunner::class)]
#[CoversClass(BaseCaches::class)]
#[CoversClass(BaseRetry::class)]
#[Large]
#[Group('canvas_ssr')]
final class BaseBuildTimeTest extends UnitTestCase {

  use DenoBinaryTrait;
  use WarmsUpDenoTrait;

  /**
   * A preload module whose top level takes 1.5 s.
   */
  private const SLOW = 'const end = Date.now() + 1500; while (Date.now() < end); export default () => "slow";';

  /**
   * A preload module whose top level never finishes.
   */
  private const ENDLESS = 'while (true) {} export default () => "never";';

  /**
   * A preload module that's quick.
   */
  private const QUICK = 'export default () => "quick";';

  /**
   * Holds this test's base caches and runner caches.
   */
  private string $directory;

  /**
   * How many times a runner called a $getDefinition.
   */
  private int $getDefinitionCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    self::skipWithoutDeno();
    $this->directory = self::makeTemporaryDirectory('base-build-time');
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
   * Tests a slow build falls back, and its retry builds it.
   */
  public function testSlowBuildFallsBackAndTheRetryBuildsIt(): void {
    $timeouts = ['medium' => 400, 'long' => 5000];

    $started = \microtime(TRUE);
    self::assertFalse($this->useBase($this->runner($timeouts), 'slow', self::SLOW));
    self::assertLessThan(1.2, \microtime(TRUE) - $started, 'The request waited past the medium limit.');
    self::assertSame([], $this->builtBaseCaches(), 'A build that ran out of time left something.');
    $record = BaseRetry::read($this->baseCaches());
    self::assertNotNull($record);
    self::assertSame('slow', $record['base']);
    self::assertTrue(BaseRetry::isDue($record, 'slow'));

    // Until it's built, renders don't wait, or build.
    $this->getDefinitionCalls = 0;
    $started = \microtime(TRUE);
    self::assertFalse($this->useBase($this->runner($timeouts), 'slow', self::SLOW));
    self::assertLessThan(0.2, \microtime(TRUE) - $started);
    self::assertSame(0, $this->getDefinitionCalls);

    self::assertTrue($this->runner($timeouts)->buildPendingBase('slow', $this->definition('slow', self::SLOW)));
    self::assertNull(BaseRetry::read($this->baseCaches()));
    self::assertCount(1, $this->builtBaseCaches());
    // Deno keeps compiled code, not what evaluating it left, so invoking it
    // would block again. That's the component's own islands' cost.
    self::assertTrue($this->useBase($this->runner($timeouts), 'slow', self::SLOW));
  }

  /**
   * Tests a build that never finishes backs off, until the base changes.
   */
  public function testEndlessBuildBacksOffUntilTheBaseChanges(): void {
    $timeouts = ['medium' => 300, 'long' => 600];
    self::assertFalse($this->useBase($this->runner($timeouts), 'endless', self::ENDLESS));

    $started = \microtime(TRUE);
    $record = $this->runner($timeouts)->buildPendingBase('endless', $this->definition('endless', self::ENDLESS));
    self::assertLessThan(2.0, \microtime(TRUE) - $started, 'The retry ran past the long limit.');
    self::assertIsArray($record);
    self::assertEqualsWithDelta(\time() + BaseRetry::FIRST_INTERVAL, $record['due'], 5);
    self::assertSame([], $this->builtBaseCaches());

    // Not due again yet, so nothing is tried.
    $this->getDefinitionCalls = 0;
    self::assertNull($this->runner($timeouts)->buildPendingBase('endless', $this->definition('endless', self::ENDLESS)));
    self::assertSame(0, $this->getDefinitionCalls);

    // A new base hasn't failed, so it's tried at once.
    self::assertTrue($this->runner($timeouts)->buildPendingBase('fixed', $this->definition('fixed', self::QUICK)));
    self::assertNull(BaseRetry::read($this->baseCaches()));
    self::assertTrue($this->useBase($this->runner($timeouts), 'fixed', self::QUICK));
  }

  /**
   * Tests a render tries a new base while an old one waits for its retry.
   *
   * The record stays until the retry, which then invalidates the old base's
   * fallbacks.
   */
  public function testNewBaseIsTriedWhileAnOldOneWaits(): void {
    $timeouts = ['medium' => 300, 'long' => 600];
    self::assertFalse($this->useBase($this->runner($timeouts), 'endless', self::ENDLESS));

    self::assertTrue($this->useBase($this->runner($timeouts), 'fixed', self::QUICK));
    $runner = $this->runner($timeouts);
    self::assertTrue($runner->hasPendingBase());
    self::assertTrue($runner->buildPendingBase('fixed', $this->definition('fixed', self::QUICK)));
    self::assertFalse($runner->hasPendingBase());
  }

  /**
   * Tests a build is retried when base caches are under the runner caches.
   *
   * A runner that can't use the base caches root keeps them, and the record
   * of a build to retry, under its runner caches root.
   */
  public function testBuildIsRetriedWithoutABaseCachesRoot(): void {
    \file_put_contents($this->directory . '/base-caches', '');
    $timeouts = ['medium' => 300, 'long' => 3000];
    self::assertFalse($this->useBase($this->runner($timeouts), 'slow', self::SLOW));

    $runner = $this->runner($timeouts);
    self::assertTrue($runner->hasPendingBase());
    self::assertTrue($runner->buildPendingBase('slow', $this->definition('slow', self::SLOW)));
    self::assertFalse($this->runner($timeouts)->hasPendingBase());
  }

  /**
   * Tests a render that gives up on a build says so.
   *
   * Its retry won't, if it succeeds.
   */
  public function testGivingUpIsLogged(): void {
    $logger = new CollectingLogger();
    $runner = new DenoRunner(self::denoBinary(), ['medium' => 300], $this->directory . '/base-caches', $this->directory . '/runner-caches', $logger);

    self::assertFalse($this->useBase($runner, 'endless', self::ENDLESS));

    $notices = $logger->at('notice');
    self::assertCount(1, $notices);
    self::assertStringStartsWith('A render gave up on building the SSR base', $notices[0]);
  }

  /**
   * Tests a request waits for another process's build only so long.
   *
   * It waits until that build's limit runs out.
   *
   * The other build is stood in for by holding its lock, with the time it
   * started recorded in it.
   */
  public function testWaitForAnotherBuildEndsWithThatBuildsLimit(): void {
    $timeouts = ['medium' => 1000];
    $runner = $this->runner($timeouts);
    self::assertTrue($runner->isAvailable());
    $base_caches = (new \ReflectionMethod(DenoRunner::class, 'baseCaches'))->invoke($runner);
    $base_cache = (new \ReflectionMethod(BaseCaches::class, 'directoryFor'))->invoke($base_caches, 'quick');
    $lock = \fopen($base_cache . '.lock', 'c');
    self::assertIsResource($lock);
    self::assertTrue(\flock($lock, LOCK_EX));
    // It has 200 ms of its limit left.
    \fwrite($lock, \sprintf('%.6F', \microtime(TRUE) - 0.8));
    \fflush($lock);

    $started = \microtime(TRUE);
    self::assertFalse($this->useBase($runner, 'quick', self::QUICK));
    $waited = \microtime(TRUE) - $started;
    self::assertGreaterThan(0.1, $waited);
    self::assertLessThan(0.6, $waited);
    self::assertNotNull(BaseRetry::read($this->baseCaches()));

    // Once it lets go, the retry builds it.
    \flock($lock, LOCK_UN);
    \fclose($lock);
    self::assertTrue($this->runner($timeouts)->buildPendingBase('quick', $this->definition('quick', self::QUICK)));
  }

  /**
   * Tests waiting for a build that fails doesn't then build it too.
   *
   * Otherwise every process that waited would build it in turn, each for as
   * long as its own limit.
   */
  public function testWaitForAFailedBuildEndsWithIt(): void {
    $timeouts = ['medium' => 2000];
    $runner = $this->runner($timeouts);
    self::assertTrue($runner->isAvailable());
    $base_caches = (new \ReflectionMethod(DenoRunner::class, 'baseCaches'))->invoke($runner);
    $base_cache = (new \ReflectionMethod(BaseCaches::class, 'directoryFor'))->invoke($base_caches, 'quick');
    // Another process builds for 300 ms, and fails.
    $holder = \proc_open([\PHP_BINARY, '-r', <<<'PHP'
      $lock = fopen($argv[1], 'c');
      flock($lock, LOCK_EX);
      fwrite($lock, sprintf('%.6F', microtime(TRUE)));
      fflush($lock);
      echo "locked\n";
      usleep(300000);
      flock($lock, LOCK_UN);
      PHP, '--', $base_cache . '.lock',
    ], [1 => ['pipe', 'w']], $pipes);
    self::assertIsResource($holder);
    self::assertSame("locked\n", \fgets($pipes[1]));

    $started = \microtime(TRUE);
    self::assertFalse($this->useBase($runner, 'quick', self::QUICK));
    self::assertLessThan(1.0, \microtime(TRUE) - $started);
    self::assertSame([], $this->builtBaseCaches());
    self::assertNotNull(BaseRetry::read($this->baseCaches()));
    \proc_close($holder);
  }

  /**
   * Returns a runner caching under this test's directory.
   *
   * @param array{short?: int, medium?: int, long?: int} $timeouts
   *   The time limits, in milliseconds.
   */
  private function runner(array $timeouts): DenoRunner {
    return new DenoRunner(self::denoBinary(), $timeouts, $this->directory . '/base-caches', $this->directory . '/runner-caches');
  }

  /**
   * Uses a base whose one module is also its preload module.
   *
   * @return bool|array<string>
   *   What useBase() returned.
   */
  private function useBase(DenoRunner $runner, string $id, string $source): bool|array {
    self::assertTrue($runner->isAvailable());
    $result = $runner->useBase($id, $this->definition($id, $source));
    if ($result !== FALSE) {
      $runner->addModule('/' . $id . '.js', $source);
    }

    return $result;
  }

  /**
   * Returns a $getDefinition for a base of one preload module.
   *
   * @return \Closure(): array{0: string, 1: array{modules: array<string, string>, preload: list<string>}}
   *   The closure, which counts its calls.
   */
  private function definition(string $id, string $source): \Closure {
    return function () use ($id, $source): array {
      $this->getDefinitionCalls++;
      $url = '/' . $id . '.js';

      return [$id, ['modules' => [$url => $source], 'preload' => [$url], 'importMap' => []]];
    };
  }

  /**
   * Returns the base caches that were built, without lock files.
   *
   * @return list<string>
   *   Their paths.
   */
  private function builtBaseCaches(): array {
    return self::paths($this->baseCaches() . '/base-*', GLOB_ONLYDIR);
  }

  /**
   * Returns where the runners keep the OS user's base caches.
   */
  private function baseCaches(): string {
    return $this->directory . '/base-caches/drupal-' . \posix_geteuid() . '/canvas/ssr/deno';
  }

}
