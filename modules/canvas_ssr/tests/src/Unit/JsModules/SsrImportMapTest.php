<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas\GlobalImports;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\SiteImportMap;
use Drupal\canvas_ssr\JsModules\SsrImportMap;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(SsrImportMap::class)]
#[Group('canvas_ssr')]
final class SsrImportMapTest extends UnitTestCase {

  /**
   * Tests a specifier resolves as runners resolve it.
   *
   * An exact key wins over a prefix, the longest prefix wins, and a key that
   * matches but maps to nothing blocks the specifier rather than letting a
   * less specific key answer. So does a "prefix/" key whose URL lacks the
   * trailing slash. A rooted path or URL no key claims is its own answer.
   */
  public function testResolveFollowsTheImportMapRules(): void {
    $imports = [
      'lib' => '/lib.js?v=3',
      'lib/' => '/lib/',
      'lib/deep/' => '/deep/',
      'blocked' => NULL,
      'blocked/' => '/blocked/',
      'sloppy/' => '/sloppy',
      '/claimed.js' => '/elsewhere.js#x',
    ];
    $cases = [
      'lib' => '/lib.js',
      'lib/a.js' => '/lib/a.js',
      'lib/deep/b.js' => '/deep/b.js',
      'blocked' => NULL,
      'blocked/c.js' => '/blocked/c.js',
      'sloppy/d.js' => NULL,
      'missing' => NULL,
      '/claimed.js' => '/elsewhere.js',
      '/rooted.js?q' => '/rooted.js',
      'https://cdn.example.com/e.js' => 'https://cdn.example.com/e.js',
    ];
    foreach ($cases as $specifier => $expected) {
      self::assertSame($expected, SsrImportMap::resolveIn($imports, (string) $specifier), (string) $specifier);
    }
  }

  /**
   * Tests the map is the site import map with provided specifiers winning.
   *
   * The site import map's import map entries are kept as they are, so
   * import.meta.resolve() answers as the browser does, except that a provided
   * module's specifier replaces the site's. That's how the SSR-safe swr shim
   * stands in for the browser's bundle.
   */
  public function testMapIsTheSitesWithProvidedSpecifiers(): void {
    $scoped = ['/canvas/api/v0/config/js_component/a' => ['@/components/b' => '/canvas/api/v0/config/js_component/b']];
    $map = new SsrImportMap(
      $this->siteImportMap([
        ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => [
          'preact' => '/canvas/assets/preact.js?a1b2c3',
          'swr' => '/canvas/assets/swr.js?a1b2c3',
        ],
        ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => $scoped,
      ]),
      new AllProvidedModules([
        new AllProvidedModulesStaticProvider([
          new ProvidedModule(url: '/_canvas_ssr/ssr-only/swr-shim.js', read: static fn() => 'shim', specifiers: ['swr']),
        ]),
      ]),
    );

    self::assertSame([
      ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => [
        'swr' => '/_canvas_ssr/ssr-only/swr-shim.js',
        'preact' => '/canvas/assets/preact.js?a1b2c3',
      ],
      ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => $scoped,
    ], $map->get());
  }

  /**
   * Tests a site import map whose imports aren't a keyed list counts as empty.
   */
  public function testMalformedSiteImportsAreDropped(): void {
    $map = new SsrImportMap(
      $this->siteImportMap([ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => 'nonsense']),
      new AllProvidedModules([
        new AllProvidedModulesStaticProvider([
          new ProvidedModule(url: '/shim.js', read: static fn() => 'shim', specifiers: ['swr']),
        ]),
      ]),
    );

    self::assertSame(
      [ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['swr' => '/shim.js']],
      $map->get(),
    );
  }

  /**
   * Tests the map is the same array until the site import map changes.
   *
   * Runners skip a repeat map with a cheap ===. A push mid-request must
   * leave nothing of the old map.
   */
  public function testMapFollowsTheSiteImportMap(): void {
    $global_imports = $this->createMock(GlobalImports::class);
    $global_imports->method('getImportMap')->willReturnOnConsecutiveCalls(
      [ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['preact' => '/first/preact.js']],
      [ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['react' => '/second/react.js']],
    );
    $site_import_map = new SiteImportMap(
      static fn () => $global_imports,
      $this->createMock(CacheTagsChecksumInterface::class),
    );
    $map = new SsrImportMap($site_import_map, new AllProvidedModules([]));
    $first = $map->get();
    self::assertSame([ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['preact' => '/first/preact.js']], $first);
    self::assertSame($first, $map->get());

    $site_import_map->invalidateTags([SiteImportMap::ASSET_LIBRARY_TAG]);
    self::assertSame([ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['react' => '/second/react.js']], $map->get());
  }

  /**
   * Tests the map follows the provided modules when their tags invalidate.
   */
  public function testMapFollowsTheProvidedModules(): void {
    $provider = new AllProvidedModulesMutableProvider(
      [new ProvidedModule(url: '/assets/lib.js', read: static fn() => 'pushed', specifiers: ['lib'])],
      ['config:canvas.asset_library.global'],
    );
    $provided = new AllProvidedModules([$provider]);
    $map = new SsrImportMap($this->siteImportMap([]), $provided);

    $first = $map->get();
    self::assertSame([ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['lib' => '/assets/lib.js']], $first);
    self::assertSame($first, $map->get());

    $provider->modules = [new ProvidedModule(url: '/assets/lib2.js', read: static fn() => 're-pushed', specifiers: ['lib'])];
    $provided->invalidateTags(['config:canvas.asset_library.global']);
    self::assertSame([ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => ['lib' => '/assets/lib2.js']], $map->get());
  }

  /**
   * Returns a site import map that reads a fixed map from GlobalImports.
   *
   * @param array<string, mixed> $import_map
   *   The map, in the shape Canvas attaches to an island.
   *
   * @return \Drupal\canvas_ssr\JsModules\SiteImportMap
   *   The site import map.
   */
  private function siteImportMap(array $import_map): SiteImportMap {
    $global_imports = $this->createMock(GlobalImports::class);
    $global_imports->method('getImportMap')->willReturn($import_map);

    return new SiteImportMap(
      static fn () => $global_imports,
      $this->createMock(CacheTagsChecksumInterface::class),
    );
  }

}
