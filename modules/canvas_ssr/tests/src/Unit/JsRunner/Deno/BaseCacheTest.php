<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\BaseCaches;
use Drupal\canvas_ssr\JsRunner\Deno\BaseManifest;
use Drupal\canvas_ssr\JsRunner\Deno\DenoRunner;
use Drupal\canvas_ssr\JsRunner\Deno\ModuleSeeder;
use Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectory;
use Drupal\canvas_ssr\JsRunner\Deno\OsUserDirectoryChangedException;
use Drupal\canvas_ssr\JsRunner\Deno\RunnerCache;
use Drupal\Tests\canvas_ssr\CollectingLogger;
use Drupal\Tests\canvas_ssr\FileAccessRecorder;
use Drupal\Tests\canvas_ssr\Traits\WarmsUpDenoTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use Psr\Log\LoggerInterface;

/**
 * Tests how the Deno runner builds base caches and links them in.
 *
 * The conformance suite checks output is the same with or without a base.
 * This checks the base cache is built once per base caches directory,
 * linked rather than copied, used instead of seeding, and never written to.
 *
 * @see \Drupal\canvas_ssr\JsRunner\Deno\DenoRunner::useBase()
 */
#[CoversClass(DenoRunner::class)]
#[CoversClass(BaseCaches::class)]
#[CoversClass(BaseManifest::class)]
#[CoversClass(OsUserDirectory::class)]
#[CoversClass(RunnerCache::class)]
#[Large]
#[Group('canvas_ssr')]
final class BaseCacheTest extends UnitTestCase {

  use DenoBinaryTrait;
  use WarmsUpDenoTrait;

  private const DEP = 'export const value = "D";';

  private const MAIN = 'import { value } from "dep"; export default () => value;';

  /**
   * Holds this test's base caches and runner caches.
   */
  private string $directory;

  /**
   * How many times the runner called useBase()'s $getDefinition.
   */
  private int $getDefinitionCalls = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = self::makeTemporaryDirectory('base-cache');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::removeTree($this->directory);
    parent::tearDown();
  }

  /**
   * Tests a base cache is built once, linked in, and left as it was built.
   */
  public function testBaseCacheIsBuiltOnceAndLinkedIntoEachRunnerCache(): void {
    $runner = $this->runner();
    self::assertSame('D', $this->render($runner));

    self::assertSame(1, $this->getDefinitionCalls);
    $base_caches = self::paths($this->baseCaches() . '/base-*', GLOB_ONLYDIR);
    self::assertCount(1, $base_caches);
    $base_cache = $base_caches[0];
    $manifest = \json_decode((string) \file_get_contents($base_cache . '/manifest.json'), TRUE);
    self::assertIsArray($manifest);
    $urls = $manifest['urls'];
    \sort($urls);
    self::assertSame(['/dep.js', '/m.js'], $urls);
    self::assertSame(['urls', 'unloadable', 'imports'], \array_keys($manifest));
    self::assertSame([], $manifest['unloadable']);
    self::assertSame(['dep' => '/dep.js'], $manifest['imports']);

    // The runner cache links the base cache's modules in, whole.
    $runner_caches = self::paths($this->runnerCaches() . '/*', GLOB_ONLYDIR);
    self::assertCount(1, $runner_caches);
    $link = $runner_caches[0] . '/remote/http/' . self::baseOriginDirectory();
    self::assertTrue(\is_link($link));
    self::assertSame(
      \realpath($base_cache . '/remote/http/' . self::baseOriginDirectory()),
      \realpath($link),
    );
    // Deno before 2.9.7 reads it under its old name.
    self::assertSame(\realpath($link), \realpath($runner_caches[0] . '/remote/http/127.0.0.1_PORT2'));
    // A render the base covers seeded none of its own.
    self::assertSame([], self::paths($runner_caches[0] . '/remote/http/' . ModuleSeeder::originDirectory(ModuleSeeder::ORIGIN) . '/*'));

    $before = self::fingerprint($base_cache);
    $runner->reset();
    self::assertSame([], self::paths($this->runnerCaches() . '/*', GLOB_ONLYDIR));
    // Removing the runner cache neither followed the link nor copied
    // anything back.
    self::assertSame($before, self::fingerprint($base_cache));

    // A new runner, like another process, finds the base cache rather than
    // building it again. Its import map comes from the manifest, since the
    // runner isn't given the base.
    self::assertSame('D', $this->render($this->runner()));
    self::assertSame(1, $this->getDefinitionCalls);
  }

  /**
   * Tests the unloadable preload modules are listed with the base cache.
   *
   * Only those: the build has no bindings, so a preload module that throws
   * for want of one may render.
   */
  public function testUnloadablePreloadModulesAreListed(): void {
    $this->skipWithoutDeno();
    $modules = [
      '/ok.js' => self::DEP,
      '/missing.js' => 'import "/nowhere.js"; export default () => "";',
      '/broken.js' => 'export const = ;',
      '/unmapped.js' => 'import "bare-thing"; export default () => "";',
      '/unbound.js' => 'export const got = globalThis.fromPhp(); export default () => got;',
    ];
    $runner = $this->runner();
    self::assertTrue($runner->isAvailable());
    $base = ['modules' => $modules, 'preload' => \array_keys($modules), 'importMap' => ['imports' => []]];
    $expected = ['/missing.js', '/broken.js', '/unmapped.js'];
    self::assertSame($expected, $runner->useBase('base', static fn() => ['base', $base]));

    // Read from the base cache, as another process would.
    $other = $this->runner();
    self::assertSame($expected, $other->useBase('base', static fn() => self::fail('The base cache was built again.')));
    $other->bindFunction('fromPhp', static fn(): string => '"bound"', '() => ""', '(s) => JSON.parse(s)');
    self::assertSame('bound', $other->invoke('/unbound.js', '""'));
    $other->reset();
  }

  /**
   * Tests a module outside the base is seeded and imports from the base.
   */
  public function testModuleOutsideTheBaseIsSeededBesideIt(): void {
    $runner = $this->runner();
    $this->useBase($runner);
    $runner->addModule('/other.js', 'import { value } from "dep"; export default () => value + "!";');

    self::assertSame('D!', $runner->invoke('/other.js', '""'));
    $runner_caches = self::paths($this->runnerCaches() . '/*', GLOB_ONLYDIR);
    self::assertCount(1, self::paths($runner_caches[0] . '/remote/http/' . ModuleSeeder::originDirectory(ModuleSeeder::ORIGIN) . '/*'));
    $runner->reset();
  }

  /**
   * Tests using a base cache touches every file in it, not just the manifest.
   *
   * So a cleaner that ages out files one by one keeps the whole base cache.
   */
  public function testUseRefreshesEveryFileOfTheBaseCache(): void {
    self::assertSame('D', $this->render($this->runner()));
    $base_caches = self::paths($this->baseCaches() . '/base-*', GLOB_ONLYDIR);
    self::assertCount(1, $base_caches);
    $paths = \array_keys(\iterator_to_array(new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($base_caches[0], \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    )));
    self::assertNotEmpty($paths);
    $old = \time() - 7200;
    foreach ([...$paths, $base_caches[0]] as $path) {
      \touch((string) $path, $old, $old);
    }
    \clearstatcache();

    self::assertSame('D', $this->render($this->runner()));

    \clearstatcache();
    foreach ([...$paths, $base_caches[0]] as $path) {
      self::assertGreaterThan($old, \filemtime((string) $path), (string) $path);
    }
  }

  /**
   * Tests a build keeps the most recently used base caches, however old.
   */
  public function testBuildKeepsTheMostRecentlyUsedBaseCaches(): void {
    // A hundred base caches unused for days, the first the longest.
    \mkdir($this->baseCaches(), 0700, TRUE);
    for ($i = 0; $i < 100; $i++) {
      $fake = \sprintf('%s/base-fake%03d', $this->baseCaches(), $i);
      \mkdir($fake);
      \file_put_contents($fake . '/manifest.json', '{"urls":[]}');
      \touch($fake . '/manifest.json', \time() - 3 * 86400 + $i);
      \touch($fake . '.lock');
    }

    self::assertSame('D', $this->render($this->runner()));

    $base_caches = self::paths($this->baseCaches() . '/base-*', GLOB_ONLYDIR);
    self::assertCount(100, $base_caches);
    self::assertNotContains($this->baseCaches() . '/base-fake000', $base_caches);
    self::assertFileDoesNotExist($this->baseCaches() . '/base-fake000.lock');
    self::assertContains($this->baseCaches() . '/base-fake001', $base_caches);
  }

  /**
   * Tests a build skips a base cache a runner is linking in.
   */
  public function testBuildKeepsABaseCacheInUse(): void {
    \mkdir($this->baseCaches(), 0700, TRUE);
    for ($i = 0; $i < 100; $i++) {
      $fake = \sprintf('%s/base-fake%03d', $this->baseCaches(), $i);
      \mkdir($fake);
      \file_put_contents($fake . '/manifest.json', '{"urls":[]}');
      \touch($fake . '/manifest.json', \time() - 3 * 86400 + $i);
    }
    // The least recently used, which pruning would delete.
    $lock = \fopen($this->baseCaches() . '/base-fake000.lock', 'c');
    self::assertNotFalse($lock);
    self::assertTrue(\flock($lock, LOCK_SH));
    try {
      self::assertSame('D', $this->render($this->runner()));
    }
    finally {
      \flock($lock, LOCK_UN);
      \fclose($lock);
    }

    self::assertDirectoryExists($this->baseCaches() . '/base-fake000');
    self::assertFileExists($this->baseCaches() . '/base-fake000.lock');
    self::assertCount(101, self::paths($this->baseCaches() . '/base-*', GLOB_ONLYDIR));
  }

  /**
   * Tests a base cache deleted while a process remembers it is rebuilt.
   *
   * Whatever is left where it was, nothing is made there in its place, and
   * the next build replaces what's left.
   */
  public function testDeletedBaseCacheIsRebuilt(): void {
    $leftovers = [
      'nothing' => static function (string $path): void {},
      'a stray file' => static function (string $path): void {
        \file_put_contents($path, '');
      },
      'an empty directory' => static function (string $path): void {
        \mkdir($path);
      },
      'a base cache deleted part way' => static function (string $path): void {
        \mkdir($path . '/remote', 0700, TRUE);
      },
    ];
    foreach ($leftovers as $leftover => $leave) {
      // A new path each time, which no process has read a manifest for.
      self::removeTree($this->directory);
      $this->directory = self::makeTemporaryDirectory('base-cache');
      $this->getDefinitionCalls = 0;
      self::assertSame('D', $this->render($this->runner()));
      $base_caches = self::paths($this->baseCaches() . '/base-*', GLOB_ONLYDIR);
      self::assertCount(1, $base_caches);
      $base_cache = $base_caches[0];
      // Due a refresh, which once made a file where it was.
      \touch($base_cache . '/manifest.json', \time() - 7200);
      self::removeTree($base_cache);
      $leave($base_cache);
      \clearstatcache();
      $before = @\lstat($base_cache);

      // This process still has its manifest, so renders without it.
      self::assertSame('D', $this->render($this->runner()), $leftover);
      \clearstatcache();
      self::assertSame($before, @\lstat($base_cache), $leftover);
      self::assertSame(1, $this->getDefinitionCalls, $leftover);

      // The next request rebuilds it.
      self::assertSame('D', $this->render($this->runner()), $leftover);
      self::assertSame(2, $this->getDefinitionCalls, $leftover);
      self::assertFileExists($base_cache . '/manifest.json', $leftover);
      self::assertSame('D', $this->render($this->runner()), $leftover);
      self::assertSame(2, $this->getDefinitionCalls, $leftover);
    }
  }

  /**
   * Tests a build deletes builds a crash left, and not ones in progress.
   */
  public function testBuildDeletesOnlyAbandonedBuilds(): void {
    \mkdir($this->baseCaches(), 0700, TRUE);
    $abandoned = $this->baseCaches() . '/base-crashed.5f1a2b3c4d5e6.12345678.tmp';
    \mkdir($abandoned);
    $in_progress = $this->baseCaches() . '/base-building.5f1a2b3c4d5e6.12345678.tmp';
    \mkdir($in_progress);
    $lock = \fopen($this->baseCaches() . '/base-building.lock', 'c');
    self::assertNotFalse($lock);
    self::assertTrue(\flock($lock, LOCK_EX));
    try {
      self::assertSame('D', $this->render($this->runner()));
    }
    finally {
      \flock($lock, LOCK_UN);
      \fclose($lock);
    }

    self::assertDirectoryDoesNotExist($abandoned);
    self::assertDirectoryExists($in_progress);
  }

  /**
   * Tests each OS user gets a directory of its own under a shared root.
   */
  public function testEachOsUserGetsItsOwnDirectory(): void {
    self::assertSame('D', $this->render($this->runner()));

    self::assertSame(
      [self::osUserDirectoryName()],
      \array_map(\basename(...), self::paths($this->directory . '/base-caches/*')),
    );
    // Shared by every OS user, like /tmp.
    self::assertSame(01777, \fileperms($this->directory . '/base-caches') & 07777);
    self::assertSame(0700, \fileperms(\dirname($this->baseCaches(), 3)) & 07777);
  }

  /**
   * Tests an OS user's directory others may write to is made private.
   *
   * It's this OS user's, so narrowing it is safe. Left as it was, whoever
   * could write there could put modules into this OS user's renders.
   */
  public function testDirectoryOthersMayWriteIsMadePrivate(): void {
    $this->skipWithoutDeno();
    foreach ([$this->baseCaches(), $this->runnerCaches()] as $directory) {
      if (!\is_dir($directory)) {
        \mkdir($directory, 0700, TRUE);
      }
      \chmod(\dirname($directory, 3), 0777);
      self::assertTrue($this->runner()->isAvailable(), $directory);
      \clearstatcache();
      self::assertSame(0700, \fileperms(\dirname($directory, 3)) & 07777, $directory);
    }
  }

  /**
   * Tests each runner checks the cache roots once.
   *
   * A runner is made for each request, so a directory deleted and replaced
   * between requests is caught by the next request's check. Making a
   * runner touches nothing.
   */
  public function testCacheRootsAreCheckedOncePerRunner(): void {
    $this->skipWithoutDeno();
    $under_roots = fn(): array => \array_values(\array_filter(
      FileAccessRecorder::$accessed,
      fn(string $access): bool => \str_contains($access, $this->directory),
    ));

    foreach (['first request', 'later request'] as $when) {
      FileAccessRecorder::start();
      try {
        $runner = $this->runner();
        $made = $under_roots();
        self::assertTrue($runner->isAvailable(), $when);
        $checked = $under_roots();
        FileAccessRecorder::$accessed = [];
        self::assertTrue($runner->isAvailable(), $when);
        $checked_again = $under_roots();
      }
      finally {
        FileAccessRecorder::stop();
      }
      self::assertSame([], $made, $when);
      self::assertNotSame([], $checked, $when);
      self::assertSame([], $checked_again, $when);
    }
  }

  /**
   * Tests a runner stops when an OS user's directory is replaced in use.
   *
   * Only root can delete the directory under a sticky root, after which
   * another OS user can claim its name. Deno reads from the directory
   * whenever it has control, so it's checked before each message PHP sends,
   * a binding's reply included, and before PHP writes to it. Once it's
   * replaced, nothing in it is deleted either: what's at the runner cache's
   * path is the other OS user's.
   */
  public function testRunnerStopsWhenItsDirectoryIsReplaced(): void {
    $this->skipWithoutDeno();
    if (\posix_geteuid() !== 0) {
      self::markTestSkipped('Making a directory another OS user owns needs root.');
    }
    // The other OS user puts their own file where each runner cache was.
    $planted = [];
    $replace = function () use (&$planted): void {
      $runner_caches = self::paths($this->runnerCaches() . '/*', GLOB_ONLYDIR);
      self::assertNotEmpty($runner_caches);
      $os_user_directory = \dirname($this->runnerCaches(), 3);
      self::removeTree($os_user_directory);
      $planted = [];
      foreach ($runner_caches as $runner_cache) {
        \mkdir($runner_cache, 0777, TRUE);
        \file_put_contents($runner_cache . '/theirs', '');
        $planted[] = $runner_cache . '/theirs';
      }
      \chown($os_user_directory, 65534);
    };
    $untouched = function () use (&$planted): void {
      foreach ($planted as $file) {
        self::assertFileExists($file, 'Nothing in the replacement was deleted.');
        self::assertSame(['theirs'], \array_values(\array_diff((array) \scandir(\dirname($file)), ['.', '..'])), 'Nothing was written to the replacement.');
      }
    };

    // Between invokes, so the next one's seeding and command are refused.
    $logger = new CollectingLogger();
    $runner = $this->runner($logger);
    self::assertSame('D', $this->render($runner));
    $replace();
    $runner->addModule('/other.js', 'export default () => "other";');
    try {
      $runner->invoke('/other.js', '""');
      self::fail('The runner used a replaced directory.');
    }
    catch (OsUserDirectoryChangedException) {
      $this->addToAssertionCount(1);
    }
    $untouched();
    // An error, which is throttled less than the warnings.
    self::assertCount(1, $logger->at('error'));
    self::assertStringContainsString('SSR stopped using a Deno directory', $logger->at('error')[0]);
    unset($runner);
    $untouched();

    // While a binding runs, so its reply isn't sent.
    self::removeTree(\dirname($this->runnerCaches(), 3));
    $runner = $this->runner();
    self::assertTrue($runner->isAvailable());
    $runner->bindFunction('probe', static function () use ($replace): string {
      $replace();
      return '"./late.js"';
    }, '() => ""', '(raw) => JSON.parse(raw)');
    $runner->addModule('/late.js', 'export default "late";');
    $runner->addModule('/m.js', 'export default async () => (await import(probe())).default;');
    try {
      $runner->invoke('/m.js', '""');
      self::fail('The runner sent a binding\'s reply into a replaced directory.');
    }
    catch (OsUserDirectoryChangedException) {
      $this->addToAssertionCount(1);
    }
    $runner->reset();
    unset($runner);
    $untouched();
  }

  /**
   * Tests a cache directory that can't be used is logged and explained.
   *
   * Here another OS user could have made it first, which turns SSR off
   * until root deletes it. So the message says who owns it, and how to fix
   * it.
   */
  public function testUnusableDirectoryIsLoggedAndExplained(): void {
    $this->skipWithoutDeno();
    \mkdir($this->directory . '/runner-caches');
    \mkdir($this->directory . '/elsewhere', 0700);
    \symlink($this->directory . '/elsewhere', $this->directory . '/runner-caches/' . self::osUserDirectoryName());
    $logger = new CollectingLogger();

    $runner = $this->runner($logger);
    self::assertFalse($runner->isAvailable());

    $reason = (string) $runner->unavailableReason();
    self::assertStringContainsString('is not a directory private to the OS user PHP runs as', $reason);
    self::assertStringContainsString('it is a symlink owned by uid', $reason);
    self::assertStringContainsString('Have root delete it, or set the runner\'s roots', $reason);
    self::assertStringStartsWith('The Deno runner is unavailable, since it can\'t use its cache directory: ', $reason);
    self::assertSame([$reason], $logger->at('warning'));

    // A missing binary is explained but not logged: the site may rely on
    // another runner.
    $logger = new CollectingLogger();
    $runner = new DenoRunner($this->directory . '/no-deno', [], $this->directory . '/base-caches', $this->directory . '/runner-caches', $logger);
    self::assertFalse($runner->isAvailable());
    self::assertSame(\sprintf('The Deno runner is unavailable, since there is no executable Deno binary at "%s/no-deno".', $this->directory), $runner->unavailableReason());
    self::assertSame([], $logger->messages);
  }

  /**
   * Tests base caches go under the runner caches root if need be.
   *
   * They go there if their own root can't be used, and fewer are kept.
   *
   * As when open_basedir leaves the base caches root out. Rendering with
   * them beats falling back to CSR, but they may not last, so it's a
   * limitation the status report shows, and a warning.
   */
  public function testUnusableBaseCachesRootFallsBackToTheRunnerCachesRoot(): void {
    $this->skipWithoutDeno();
    \mkdir($this->directory . '/base-caches');
    \mkdir($this->directory . '/elsewhere', 0700);
    \symlink($this->directory . '/elsewhere', $this->directory . '/base-caches/' . self::osUserDirectoryName());
    // Twelve unused base caches already there, the first the oldest.
    \mkdir($this->runnerCaches(), 0700, TRUE);
    for ($i = 0; $i < 12; $i++) {
      $fake = \sprintf('%s/base-fake%02d', $this->runnerCaches(), $i);
      \mkdir($fake);
      \file_put_contents($fake . '/manifest.json', '{"urls":[]}');
      \touch($fake . '/manifest.json', \time() - 86400 + $i);
    }
    $logger = new CollectingLogger();

    $runner = $this->runner($logger);
    self::assertSame('D', $this->render($runner));

    $base_caches = self::paths($this->runnerCaches() . '/base-*', GLOB_ONLYDIR);
    self::assertCount(10, $base_caches);
    self::assertNotContains($this->runnerCaches() . '/base-fake02', $base_caches);
    self::assertContains($this->runnerCaches() . '/base-fake03', $base_caches);
    self::assertSame([], self::paths($this->directory . '/elsewhere/*'));
    $limitations = $runner->limitations();
    self::assertCount(1, (array) \preg_grep('/keeps base caches under its runner caches root, ".*", and only 10 of them, since it can\'t use the base caches root, ".*": The Deno directory ".*" is not used/', $limitations));
    self::assertCount(1, $logger->at('warning'));
    self::assertStringContainsString('keeps base caches under its runner caches root', $logger->at('warning')[0]);
    $runner->reset();
    // Runner caches are deleted on reset, but base caches beside them stay.
    self::assertCount(10, self::paths($this->runnerCaches() . '/base-*', GLOB_ONLYDIR));
  }

  /**
   * Returns a runner caching under this test's directory.
   */
  private function runner(?LoggerInterface $logger = NULL): DenoRunner {
    return new DenoRunner(
      self::denoBinary(),
      [],
      $this->directory . '/base-caches',
      $this->directory . '/runner-caches',
      $logger,
    );
  }

  /**
   * Returns where the runners keep the OS user's base caches.
   */
  private function baseCaches(): string {
    return $this->directory . '/base-caches/' . self::osUserDirectoryName() . '/canvas/ssr/deno';
  }

  /**
   * Returns where the runners make the OS user's runner caches.
   */
  private function runnerCaches(): string {
    return $this->directory . '/runner-caches/' . self::osUserDirectoryName() . '/canvas/ssr/deno';
  }

  /**
   * Returns the name of the OS user's directory under each root.
   */
  private static function osUserDirectoryName(): string {
    return 'drupal-' . \posix_geteuid();
  }

  /**
   * Uses the base, then renders from it.
   */
  private function render(DenoRunner $runner): string {
    $this->useBase($runner);
    $runner->addModule('/dep.js', self::DEP);
    $runner->addModule('/m.js', self::MAIN);

    return $runner->invoke('/m.js', '""');
  }

  /**
   * Uses the base, giving its definition if the runner asks for it.
   */
  private function useBase(DenoRunner $runner): void {
    $this->skipWithoutDeno();
    self::assertTrue($runner->isAvailable());
    $runner->useBase('base', function (): array {
      $this->getDefinitionCalls++;
      $base = [
        'modules' => ['/dep.js' => self::DEP, '/m.js' => self::MAIN],
        'preload' => ['/m.js'],
        'importMap' => ['imports' => ['dep' => '/dep.js']],
      ];

      return ['base', $base];
    });
  }

  /**
   * Returns the directory Deno keeps the base origin's modules in.
   */
  private static function baseOriginDirectory(): string {
    return ModuleSeeder::originDirectory(ModuleSeeder::BASE_ORIGIN);
  }

  /**
   * Returns every file under a directory with a digest of its contents.
   *
   * @return array<string, string>
   *   Digests, keyed by path relative to the directory, less the manifest,
   *   whose mtime is refreshed while the base cache is in use.
   */
  private static function fingerprint(string $directory): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
      \assert($file instanceof \SplFileInfo);
      // A link, like the origin directory's legacy name, by its target.
      $files[\substr($file->getPathname(), \strlen($directory))] = $file->isLink()
        ? 'link:' . $file->getLinkTarget()
        : (string) \hash_file('sha256', $file->getPathname());
    }
    \ksort($files);

    return $files;
  }

}
