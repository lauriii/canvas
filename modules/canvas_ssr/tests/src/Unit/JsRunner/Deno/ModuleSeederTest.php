<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner\Deno;

use Drupal\canvas_ssr\JsRunner\Deno\ModuleSeeder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the cache seeding and import map that let Deno import a module.
 *
 * None of this needs Deno. DenoConformanceTest covers Deno importing the
 * result, and SandboxTest covers what a component can't reach.
 */
#[CoversClass(ModuleSeeder::class)]
#[Group('canvas_ssr')]
final class ModuleSeederTest extends UnitTestCase {

  use DenoBinaryTrait;

  /**
   * The preamble a module at /m.js mentioning import.meta is seeded with.
   */
  private const PREAMBLE = 'import.meta.resolve = (s) => globalThis.__canvasSsrResolveImportMeta(s, "/m.js");';

  /**
   * The cache directory this test seeds into.
   */
  private string $directory;

  /**
   * The seeder under test.
   */
  private ModuleSeeder $seeder;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->directory = self::makeTemporaryDirectory('module-seeder');
    $this->seeder = new ModuleSeeder($this->directory, ModuleSeeder::ORIGIN);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::removeTree($this->directory);
    parent::tearDown();
  }

  /**
   * Returns the body a module was seeded as.
   *
   * Finds it the way Deno does, by hashing the cache URL's path, so a wrong
   * filename fails here instead of as a cache miss in the Deno process.
   *
   * @param string $url
   *   The cache URL mainModuleUrl() returned.
   *
   * @return string
   *   The body, without the metadata comment Deno appends to it.
   */
  private function bodyOf(string $url): string {
    $path = \parse_url($url, PHP_URL_PATH);
    self::assertIsString($path);
    $file = \sprintf(
      '%s/remote/http/%s/%s',
      $this->directory,
      ModuleSeeder::originDirectory(ModuleSeeder::ORIGIN),
      \hash('sha256', $path),
    );
    self::assertFileExists($file, \sprintf('Nothing was seeded for "%s".', $url));
    $contents = \file_get_contents($file);
    self::assertIsString($contents);

    [$body, $metadata] = \explode("\n// denoCacheMetadata=", $contents, 2);
    // Deno reads the cached file's headers from that comment, and rejects a
    // comment it can't parse.
    $decoded = \json_decode($metadata, TRUE);
    self::assertIsArray($decoded);
    self::assertSame($url, $decoded['url'] ?? NULL);
    self::assertSame('text/javascript', $decoded['headers']['content-type'] ?? NULL);

    return $body;
  }

  /**
   * Tests a module is seeded as it is, at its cache URL.
   */
  public function testSourcesAreSeededAsTheyAre(): void {
    $source = "// leading comment\nimport x from \"./dep.js\";\nexport default () => x;";
    $this->seeder->setSource('/lib/dep.js', 'export default 1;');
    $this->seeder->setSource('/lib/m.js', $source);

    $main = $this->seeder->mainModuleUrl('/lib/m.js');

    self::assertSame(ModuleSeeder::ORIGIN . '/lib/m.js', $main);
    self::assertSame($source, $this->bodyOf($main));
    // Seeded even though it isn't the main module. The Deno process resolves
    // the import.
    self::assertSame('export default 1;', $this->bodyOf(ModuleSeeder::ORIGIN . '/lib/dep.js'));
  }

  /**
   * Tests the origin directory is also reachable under its pre-2.9.7 name.
   */
  public function testOriginDirectoryKeepsItsLegacyName(): void {
    $this->seeder->setSource('/m.js', 'export default 1;');
    $this->seeder->mainModuleUrl('/m.js');

    $remote = $this->directory . '/remote/http/';
    self::assertSame('127.0.0.1_port_1', ModuleSeeder::originDirectory(ModuleSeeder::ORIGIN));
    self::assertSame('127.0.0.1_port_1', \readlink($remote . '127.0.0.1_PORT1'));
    self::assertDirectoryExists($remote . '127.0.0.1_port_1');
  }

  /**
   * Tests a module URL is escaped the way Deno serializes the cache URL.
   *
   * @param string $path
   *   The module URL.
   * @param string $expected
   *   The URL expected.
   */
  #[DataProvider('escapedPaths')]
  public function testUrlEscapesAsDenoDoes(string $path, string $expected): void {
    self::assertSame($expected, ModuleSeeder::url(ModuleSeeder::ORIGIN, $path));
  }

  /**
   * Returns module URLs and the cache URLs Deno addresses them under.
   *
   * @return array<string, array{string, string}>
   *   The module URL and its cache URL, keyed by description.
   */
  public static function escapedPaths(): array {
    return [
      'a plain path' => ['/a/b.js', 'http://127.0.0.1:1/a/b.js'],
      'an @ and a hash' => [
        '/files/@scope/card.js~ca0f',
        'http://127.0.0.1:1/files/@scope/card.js~ca0f',
      ],
      'characters Deno leaves alone' => [
        "/^|[]'!$&()*+,;=:%",
        "http://127.0.0.1:1/^|[]'!$&()*+,;=:%",
      ],
      'characters it encodes' => [
        '/a b"#<>?`{}\\é',
        'http://127.0.0.1:1/a%20b%22%23%3C%3E%3F%60%7B%7D%5C%C3%A9',
      ],
      'an absolute URL' => [
        'https://cdn.example.com:8443/a b.js',
        'http://127.0.0.1:1/_url/https/cdn.example.com:8443/a%20b.js',
      ],
    ];
  }

  /**
   * Tests the escaped URL is what names the cache file.
   */
  public function testEscapedPathIsSeededUnderItsUrl(): void {
    $this->seeder->setSource('/a b.js', 'export default 1;');

    $main = $this->seeder->mainModuleUrl('/a b.js');

    self::assertSame(ModuleSeeder::ORIGIN . '/a%20b.js', $main);
    self::assertSame('export default 1;', $this->bodyOf($main));
  }

  /**
   * Tests only a module mentioning import.meta is given the resolver preamble.
   *
   * @param string $source
   *   The module's source.
   * @param bool $expected
   *   Whether the preamble belongs on it.
   */
  #[DataProvider('importMetaSources')]
  public function testPreambleIsPrependedOnlyWhereImportMetaIsUsed(string $source, bool $expected): void {
    $this->seeder->setSource('/m.js', $source);

    $body = $this->bodyOf($this->seeder->mainModuleUrl('/m.js'));

    self::assertSame($expected ? self::PREAMBLE . $source : $source, $body);
    // Either way, the preamble adds no newline, so line numbers hold.
    self::assertSame(\substr_count($source, "\n"), \substr_count($body, "\n"));
  }

  /**
   * Returns sources that do and do not need the import.meta preamble.
   *
   * @return array<string, array{string, bool}>
   *   The source and whether the preamble is expected, keyed by description.
   */
  public static function importMetaSources(): array {
    return [
      'a module resolving an asset' => [
        'const u = import.meta.resolve("@/logo.png");' . "\n" . 'export default () => u;',
        TRUE,
      ],
      'a module that never mentions it' => [
        'export default () => "plain";',
        FALSE,
      ],
    ];
  }

  /**
   * Tests only overwriting a module is reported as a rewrite.
   *
   * The module was already written.
   *
   * A realm that ran the old bytes never runs the new ones, so the runner
   * needs to know.
   */
  public function testOnlyAnOverwriteIsReportedAsRewritten(): void {
    $this->seeder->setSource('/m.js', 'export default () => "A";');
    $this->seeder->mainModuleUrl('/m.js');
    self::assertFalse($this->seeder->takeRewritten());

    $this->seeder->setSource('/m.js', 'export default () => "A";');
    $this->seeder->setSource('/n.js', 'export default () => "N";');
    $this->seeder->mainModuleUrl('/m.js');
    self::assertFalse($this->seeder->takeRewritten());

    $this->seeder->setSource('/m.js', 'export default () => "B";');
    $main = $this->seeder->mainModuleUrl('/m.js');
    self::assertSame('export default () => "B";', $this->bodyOf($main));
    self::assertTrue($this->seeder->takeRewritten());
    self::assertFalse($this->seeder->takeRewritten());
  }

  /**
   * Tests the SSR import map becomes the Deno import map.
   *
   * The Deno import map points at cache URLs.
   *
   * Queries and fragments are dropped, absolute URLs move under the
   * absolute prefix, and an import map entry that names no module URL
   * blocks.
   */
  public function testSsrImportMapBecomesTheDenoImportMap(): void {
    $this->seeder->setSsrImports([
      'preact' => '/vendor/preact.js?v=3',
      '@/lib/' => '/site/lib/',
      'cdn' => 'https://cdn.example.com/x.js#top',
      'blocked' => NULL,
      'bad/' => '/no-slash',
      'relative' => './x.js',
      '' => '/empty-key.js',
    ]);

    self::assertSame([
      '@/lib/' => ModuleSeeder::ORIGIN . '/site/lib/',
      'bad/' => NULL,
      'blocked' => NULL,
      'cdn' => ModuleSeeder::ORIGIN . '/_url/https/cdn.example.com/x.js',
      'preact' => ModuleSeeder::ORIGIN . '/vendor/preact.js',
      'relative' => NULL,
    ], $this->seeder->denoImportMap());
  }

  /**
   * Tests a URL key can't take over a registered URL.
   *
   * Registered URLs load as they are, so each one a URL key covers maps to
   * itself, ahead of the key.
   */
  public function testRegisteredUrlWinsOverUrlKey(): void {
    $this->seeder->setSsrImports(['/old.js' => '/new.js', '/lib/' => '/other/']);
    $before = $this->seeder->denoImportMap();
    self::assertSame([
      ModuleSeeder::ORIGIN . '/lib/' => ModuleSeeder::ORIGIN . '/other/',
      ModuleSeeder::ORIGIN . '/old.js' => ModuleSeeder::ORIGIN . '/new.js',
    ], $before);

    $this->seeder->setSource('/old.js', 'export default 1;');
    $this->seeder->setSource('/lib/a.js', 'export default 1;');
    $this->seeder->setSource('/elsewhere.js', 'export default 1;');

    self::assertSame([
      ModuleSeeder::ORIGIN . '/lib/' => ModuleSeeder::ORIGIN . '/other/',
      ModuleSeeder::ORIGIN . '/lib/a.js' => ModuleSeeder::ORIGIN . '/lib/a.js',
      ModuleSeeder::ORIGIN . '/old.js' => ModuleSeeder::ORIGIN . '/old.js',
    ], $this->seeder->denoImportMap());
  }

  /**
   * Tests the map is the same array until the SSR import map changes.
   *
   * Without URL keys, registering modules at rooted paths leaves it alone
   * too.
   */
  public function testImportMapChangesOnlyWithTheSsrImportMap(): void {
    $this->seeder->setSsrImports(['a' => '/a.js']);
    $first = $this->seeder->denoImportMap();

    $this->seeder->setSsrImports(['a' => '/a.js']);
    $this->seeder->setSource('/a.js', 'export default 1;');
    self::assertSame($first, $this->seeder->denoImportMap());

    $this->seeder->setSsrImports(['a' => '/b.js']);
    self::assertNotSame($first, $this->seeder->denoImportMap());
  }

  /**
   * Tests a name that is not a module URL is refused.
   *
   * RunnerGuard only passes module URLs, so anything else means a caller
   * bypassed it. A rooted path under ABSOLUTE_PREFIX would share a cache URL
   * with an absolute URL, so it's refused too.
   */
  #[DataProvider('refusedNames')]
  public function testNonModuleUrlIsRefused(string $name): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(\sprintf('so "%s" cannot be registered.', $name));
    $this->seeder->setSource($name, 'export default 1;');
  }

  /**
   * Returns names ModuleSeeder refuses.
   *
   * @return array<string, array{string}>
   *   The name, keyed by description.
   */
  public static function refusedNames(): array {
    return [
      'bare' => ['@/m.js'],
      'under the absolute prefix' => [ModuleSeeder::ABSOLUTE_PREFIX . 'https/cdn.example.com/m.js'],
    ];
  }

  /**
   * Tests a module at an absolute URL is seeded under ABSOLUTE_PREFIX.
   *
   * Its preamble gives it its own URL, and an import may name the URL as it
   * is, which the Deno import map takes to the cache URL.
   */
  public function testAbsoluteUrlIsSeededUnderTheAbsolutePrefix(): void {
    $url = 'https://cdn.example.com/lib/m.js';
    $this->seeder->setSource($url, 'export default import.meta.url;');

    $main = $this->seeder->mainModuleUrl($url);

    self::assertSame(ModuleSeeder::ORIGIN . '/_url/https/cdn.example.com/lib/m.js', $main);
    self::assertStringStartsWith('import.meta.url = "https://cdn.example.com/lib/m.js";', $this->bodyOf($main));
    self::assertSame([$url => $main], $this->seeder->denoImportMap());
  }

  /**
   * Tests a main module nothing is registered under is refused.
   */
  public function testUnregisteredMainModuleIsRefused(): void {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('No module is registered under "/missing.js".');
    $this->seeder->mainModuleUrl('/missing.js');
  }

  /**
   * Tests restoreUrls() puts the module URLs back into text coming out.
   */
  public function testRestoreUrlsTakesTheOriginsOff(): void {
    self::assertSame(
      'at /a.js:1:1 via /b.js:2:3, https://cdn.example.com/d.js:4:5 and http://127.0.0.1:3/c.js',
      $this->seeder->restoreUrls(\sprintf(
        'at %s/a.js:1:1 via %s/b.js:2:3, %s/_url/https/cdn.example.com/d.js:4:5 and http://127.0.0.1:3/c.js',
        ModuleSeeder::ORIGIN,
        ModuleSeeder::BASE_ORIGIN,
        ModuleSeeder::BASE_ORIGIN,
      )),
    );
  }

  /**
   * Tests only a stack trace's frames get module URLs for cache URLs.
   *
   * A binding's argument holds what a component wrote too, such as a URL
   * to fetch, which must reach PHP as written.
   */
  public function testRestoreFrameUrlsLeavesTheRestAlone(): void {
    $origin = ModuleSeeder::ORIGIN;
    $base = ModuleSeeder::BASE_ORIGIN;
    $stack = "Error\n    at $origin/a.js:1:2\n    at Object.Root ($base/b.js~hash:3:4)\n    at call ({$origin}/_url/https/example.com/c.js:5:6)\n    at async render ($base/d.js:7:8)";
    $request = \json_encode([
      'url' => "$origin/jsonapi/node?next=$base/_url/https/example.com/x",
      'headers' => ['x-from' => "at $origin/a.js:1:2"],
      'stack' => $stack,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $restored = \json_decode(ModuleSeeder::restoreFrameUrls($origin, $request), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertSame("$origin/jsonapi/node?next=$base/_url/https/example.com/x", $restored['url']);
    self::assertSame("at $origin/a.js:1:2", $restored['headers']['x-from']);
    self::assertSame("Error\n    at /a.js:1:2\n    at Object.Root (/b.js~hash:3:4)\n    at call (https://example.com/c.js:5:6)\n    at async render (/d.js:7:8)", $restored['stack']);
    // As it is, too.
    self::assertSame("Error\n    at /a.js:1:2", ModuleSeeder::restoreFrameUrls($origin, "Error\n    at $origin/a.js:1:2"));
  }

  /**
   * Tests a module a base cache has is addressed there, and not seeded.
   *
   * The Deno import map redirects each base module to the base origin, and
   * import map entries for one point straight at the base's copy. Import maps
   * don't chain, so a prefix's import map entry gets an exact one for each
   * base module under it, unless a more specific key takes that module.
   */
  public function testModulesInTheBaseCacheAreAddressedThereAndNotSeeded(): void {
    $imports = [
      'dep' => '/dep.js',
      'own' => '/own.js',
      '@/lib/' => '/lib/',
      '@/lib/x/' => NULL,
    ];
    $builder_directory = $this->directory . '-base';
    $builder = new ModuleSeeder($builder_directory, ModuleSeeder::BASE_ORIGIN);
    $builder->setSsrImports($imports);
    $builder->setSource('/dep.js', 'export const value = "D";');
    $builder->setSource('/m.js', 'import { value } from "dep"; export default () => value;');
    $builder->setSource('/lib/a.js', 'export default 1;');
    $builder->setSource('/lib/x/b.js', 'export default 1;');
    $builder->seed();
    $urls = $builder->seededUrls();
    \sort($urls);
    self::assertSame(['/dep.js', '/lib/a.js', '/lib/x/b.js', '/m.js'], $urls);
    // A base's own map holds no redirects to itself.
    self::assertSame([
      '@/lib/' => ModuleSeeder::BASE_ORIGIN . '/lib/',
      '@/lib/x/' => NULL,
      'dep' => ModuleSeeder::BASE_ORIGIN . '/dep.js',
      'own' => ModuleSeeder::BASE_ORIGIN . '/own.js',
    ], $builder->denoImportMap());
    self::removeTree($builder_directory);

    $seeder = new ModuleSeeder($this->directory, ModuleSeeder::ORIGIN, \array_fill_keys($urls, TRUE));
    $seeder->setSsrImports($imports);
    $seeder->setSource('/m.js', 'import { value } from "dep"; export default () => value;');
    $seeder->setSource('/own.js', 'export default 1;');

    self::assertSame(ModuleSeeder::BASE_ORIGIN . '/m.js', $seeder->mainModuleUrl('/m.js'));
    self::assertSame(['/own.js'], $seeder->seededUrls());
    self::assertSame([
      '@/lib/' => ModuleSeeder::ORIGIN . '/lib/',
      '@/lib/a.js' => ModuleSeeder::BASE_ORIGIN . '/lib/a.js',
      '@/lib/x/' => NULL,
      'dep' => ModuleSeeder::BASE_ORIGIN . '/dep.js',
      ModuleSeeder::ORIGIN . '/dep.js' => ModuleSeeder::BASE_ORIGIN . '/dep.js',
      ModuleSeeder::ORIGIN . '/lib/a.js' => ModuleSeeder::BASE_ORIGIN . '/lib/a.js',
      ModuleSeeder::ORIGIN . '/lib/x/b.js' => ModuleSeeder::BASE_ORIGIN . '/lib/x/b.js',
      ModuleSeeder::ORIGIN . '/m.js' => ModuleSeeder::BASE_ORIGIN . '/m.js',
      'own' => ModuleSeeder::ORIGIN . '/own.js',
    ], $seeder->denoImportMap());
  }

  /**
   * Tests a cache directory that cannot be written is reported as such.
   *
   * Otherwise Deno would report a module missing from the cache, which could
   * mean many things.
   */
  public function testCacheDirectoryThatCannotBeWrittenIsReported(): void {
    $file = \tempnam(\sys_get_temp_dir(), 'canvas-ssr-deno-test');
    self::assertIsString($file);
    // Nobody, not even root, can create a directory under a regular file.
    $seeder = new ModuleSeeder($file . '/cache', ModuleSeeder::ORIGIN);
    $seeder->setSource('/m.js', 'export default () => "A";');

    try {
      $this->expectException(\RuntimeException::class);
      $this->expectExceptionMessage('Failed to create the Deno module cache directory');
      $seeder->mainModuleUrl('/m.js');
    }
    finally {
      \unlink($file);
    }
  }

}
