<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Kernel\JsModules;

use Drupal\canvas\GlobalImports;
use Drupal\canvas_ssr\JsModules\AllProvidedModules;
use Drupal\canvas_ssr\JsModules\SsrImportMap;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Tests\canvas_ssr\Kernel\CanvasSsrKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the SSR import map against the provided modules.
 *
 * Checks the merged result of all providers: the specifiers SSR relies on are
 * in the site import map, and every import map entry loads a provided module
 * under its module URL. The scenarios don't render or change the asset
 * library, so they can share one set of provided modules.
 *
 * @legacy-covers \Drupal\canvas_ssr\JsModules\AllProvidedModules
 * @legacy-covers \Drupal\canvas_ssr\JsModules\SsrImportMap
 * @legacy-covers \Drupal\canvas_ssr\JsModules\Provider\CanvasDistModuleProvider
 *
 * @see \Drupal\Tests\canvas_ssr\Kernel\ScenariosTrait
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
#[Group('JavaScriptComponents')]
final class ImportMapCoverageTest extends CanvasSsrKernelTestBase {

  /**
   * Tests how the SSR import map resolves to provided modules.
   */
  public function testSsrImportMapResolvesToProvidedModules(): void {
    $provided = $this->container->get(AllProvidedModules::class);
    \assert($provided instanceof AllProvidedModules);
    $ssr_import_map = $this->container->get(SsrImportMap::class);
    \assert($ssr_import_map instanceof SsrImportMap);
    $global_imports = $this->container->get(GlobalImports::class);
    \assert($global_imports instanceof GlobalImports);
    $drupal_root = \realpath(DRUPAL_ROOT);
    self::assertIsString($drupal_root);

    $this->assertScenarios([
      // CanvasDistModuleProvider finds these sources through their
      // GlobalImports URLs, and components may import them directly. If
      // Canvas drops one, SSR for it breaks.
      'the backward-compat specifiers are still in the site import map' => function () use ($global_imports): void {
        $import_map = $global_imports->getImportMap();
        foreach (['@/lib/drupal-utils', '@/lib/FormattedText', '@/lib/utils', 'next-image-standalone'] as $specifier) {
          self::assertArrayHasKey(
            $specifier,
            $import_map['imports'],
            "Backward-compat import '$specifier' resolved by CanvasDistModuleProvider is missing from GlobalImports.",
          );
        }
      },

      // "drupal-canvas" maps to the bundle's URL, so the runner loads the
      // bundle itself and no export list needs maintaining. The bundle is
      // provided under that module URL, so its relative chunks load beside
      // it.
      'drupal-canvas maps to the real package bundle' => function () use ($provided, $ssr_import_map, $drupal_root): void {
        $canvas_path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'canvas');
        $bundle = \realpath(DRUPAL_ROOT . '/' . $canvas_path . '/packages/astro-hydration/dist/drupal-canvas.js');
        self::assertIsString($bundle, 'Expected the drupal-canvas bundle to exist.');

        // The module URL is the URL, not the realpath, so import.meta.url and
        // stack frames don't leak directories above the Drupal root. With
        // base_path "/", it's the path relative to DRUPAL_ROOT.
        $expected_url = \substr($bundle, \strlen($drupal_root));
        self::assertStringStartsWith('/', $expected_url);
        self::assertStringNotContainsString($drupal_root, $expected_url);

        $sources = $provided->sources();
        self::assertSame($expected_url, self::moduleUrlFor($ssr_import_map, 'drupal-canvas'));

        // The bundle loads under that module URL.
        self::assertNotSame('', $sources[$expected_url] ?? '');

        // The realpath isn't registered.
        self::assertArrayNotHasKey($bundle, $sources);
      },

      // Same as above, for every import map entry: a module URL becomes its
      // import.meta.url and appears in stack traces, so it must not reveal
      // the server's directory layout.
      'every import map entry loads a provided module under its module URL' => function () use ($provided, $ssr_import_map, $drupal_root): void {
        $specifiers = \array_keys($ssr_import_map->get()['imports'] ?? []);
        self::assertNotEmpty($specifiers);

        $sources = $provided->sources();
        foreach ($specifiers as $specifier) {
          $module_url = self::moduleUrlFor($ssr_import_map, (string) $specifier);
          self::assertNotNull($module_url, "The import map entry for '$specifier' should be a URL.");
          self::assertStringStartsWith('/', $module_url, "The module URL for '$specifier' should be a rooted path.");
          self::assertStringNotContainsString(
            $drupal_root,
            $module_url,
            "The module URL for '$specifier' leaks the absolute Drupal root path.",
          );
          self::assertArrayHasKey($module_url, $sources, "The module '$module_url' for '$specifier' is not provided.");
        }
      },
    ]);
  }

  /**
   * Returns the module URL a specifier's import map entry loads.
   *
   * @param \Drupal\canvas_ssr\JsModules\SsrImportMap $ssr_import_map
   *   The SSR import map.
   * @param string $specifier
   *   The specifier.
   *
   * @return string|null
   *   The module URL, or NULL if the import map entry isn't a URL.
   */
  private static function moduleUrlFor(SsrImportMap $ssr_import_map, string $specifier): ?string {
    $url = $ssr_import_map->get()['imports'][$specifier] ?? NULL;
    if (!\is_string($url)) {
      return NULL;
    }

    return \preg_replace('/[?#].*$/s', '', $url) ?? $url;
  }

}
