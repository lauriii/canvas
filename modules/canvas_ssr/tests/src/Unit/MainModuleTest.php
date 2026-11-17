<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit;

use Drupal\canvas_ssr\ComponentInfo\MainModule;
use Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider;
use Drupal\canvas_ssr\JsParser\JsModuleParser;
use Drupal\canvas_ssr\JsParser\ParsedModuleCache;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests the main module a component is rendered with.
 */
#[CoversClass(MainModule::class)]
#[Group('canvas_ssr')]
final class MainModuleTest extends UnitTestCase {

  /**
   * Tests the main module's imports and their order.
   *
   * It imports the realm setup module, the hydration data module, the
   * drupalSettings module, the framework renderer, then the component. The
   * realm setup module comes first, so the realm is locked before any other
   * module evaluates. The drupalSettings module comes before the component,
   * since components may read drupalSettings while they evaluate. URLs that
   * need escaping must still import what they name.
   *
   * @param string $component_url
   *   The component's module URL.
   */
  #[DataProvider('providerComponentUrls')]
  public function testMainModuleImportsSettingsRendererAndComponent(string $component_url): void {
    $component_source = 'export default () => null;';
    $main_module = MainModule::build(
      $component_url,
      'setDrupalSettings({});',
      'preact',
      static fn(): array => [$component_url => $component_source],
      [$component_url],
    );
    $sources = $main_module->sources();
    self::assertSame($component_source, $sources[$component_url], 'The component is registered with the main module.');
    $urls = \array_keys(\array_diff_key($sources, [$component_url => TRUE]));
    self::assertContains($main_module->url, $urls);
    $settings_url = \array_values(\array_diff($urls, [$main_module->url]))[0];

    $cache = new ParsedModuleCache(new NullBackend('test'), new NullLockBackend());
    $parsed = (new JsModuleParser(new NullLogger(), $cache))->parse($sources[$main_module->url], 'test');

    self::assertNotNull($parsed);
    self::assertSame(
      [
        SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER,
        SsrOnlyModuleProvider::HYDRATION_DATA_SPECIFIER,
        $settings_url,
        SsrOnlyModuleProvider::rendererSpecifier('preact'),
        $component_url,
      ],
      \array_column($parsed->getImports(), 0),
    );
  }

  /**
   * Tests the libraries module imports the libraries, in a fixed order.
   *
   * The renderer invokes it before the main module, so the libraries an
   * island's components import evaluate before the components do. The
   * framework renderer and the hydration data module come first, as in a
   * base, then the rest by specifier, each once, so components that import
   * the same libraries get the same order.
   */
  public function testLibrariesModuleImportsEachLibraryInOrder(): void {
    $component_url = '/components/c.js';
    $main_module = MainModule::build(
      $component_url,
      'setDrupalSettings({});',
      'preact',
      static fn(): array => [$component_url => 'export default () => null;'],
      [$component_url],
      ['swr', 'a "quoted" one', 'clsx', 'swr'],
    );
    $renderer = SsrOnlyModuleProvider::rendererSpecifier('preact');
    self::assertSame([$renderer, SsrOnlyModuleProvider::HYDRATION_DATA_SPECIFIER, 'a "quoted" one', 'clsx', 'swr'], $main_module->libraries);

    $sources = $main_module->generatedSources();
    self::assertArrayHasKey($main_module->librariesUrl, $sources);
    self::assertNotSame($main_module->url, $main_module->librariesUrl);
    $cache = new ParsedModuleCache(new NullBackend('test'), new NullLockBackend());
    $parsed = (new JsModuleParser(new NullLogger(), $cache))->parse($sources[$main_module->librariesUrl], 'test');
    self::assertNotNull($parsed);
    $imports = \array_column($parsed->getImports(), 0);
    // The realm setup module and the drupalSettings module, then each
    // library.
    self::assertSame(SsrOnlyModuleProvider::REALM_SETUP_SPECIFIER, $imports[0]);
    self::assertSame([$renderer, SsrOnlyModuleProvider::HYDRATION_DATA_SPECIFIER, 'a "quoted" one', 'clsx', 'swr'], \array_slice($imports, 2));
  }

  /**
   * Provides component module URLs, some that encode to a longer literal.
   *
   * @return array<string, array{string}>
   *   The module URL.
   */
  public static function providerComponentUrls(): array {
    return [
      'a URL' => ['/sites/default/files/astro-island/a.js'],
      'a URL with a subgraph hash' => ['/sites/default/files/astro-island/a.js~0123456789abcdef0123456789abcdef'],
      'escapes and multibyte text' => ['/files/"é→"\\b.js'],
    ];
  }

}
