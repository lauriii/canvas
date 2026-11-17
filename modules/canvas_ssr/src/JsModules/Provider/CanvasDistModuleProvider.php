<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules\Provider;

use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ExtensionPathResolver;

/**
 * Supplies the packages Canvas ships in its astro-hydration dist.
 *
 * These are preact, drupal-canvas, clsx, the JSON:API client and so on,
 * plus the chunks they split into.
 *
 * Each file is named by the URL the browser loads it from. That way the
 * import map entries point at these names, and a relative "./chunk.js"
 * import resolves to the same file it does in the browser. The dist is
 * flat, so there are no subdirectories to scan.
 *
 * The whole directory is provided, not just what the import map lists, so
 * the chunks are included. Its contents aren't checked: Canvas ships them.
 * The site's own pushed code is CanvasPushedManifestModuleProvider's, and is
 * checked there.
 *
 * SsrOnlyModuleProvider replaces the swr provided here, and supplies
 * preact-render-to-string from this dist as an SSR-only module.
 *
 * @see \Drupal\canvas\GlobalImports::getImportMap()
 * @see \Drupal\canvas_ssr\JsModules\SiteImportMap
 * @see \Drupal\canvas_ssr\JsModules\Provider\CanvasPushedManifestModuleProvider
 * @see \Drupal\canvas_ssr\JsModules\Provider\SsrOnlyModuleProvider
 */
final readonly class CanvasDistModuleProvider implements ModuleProviderInterface {

  /**
   * Canvas's built hydration bundle, relative to the canvas module root.
   *
   * SsrOnlyModuleProvider reads one of its files too.
   */
  public const string DIST_DIRECTORY = '/packages/astro-hydration/dist/';

  public function __construct(
    private ExtensionPathResolver $extensionPathResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getModules(): ProvidedModules {
    $canvas_path = $this->extensionPathResolver->getPath('module', 'canvas');
    $directory = DRUPAL_ROOT . '/' . $canvas_path . self::DIST_DIRECTORY;
    $web_base = \base_path() . $canvas_path . self::DIST_DIRECTORY;

    // glob() returns FALSE on error. Treat that like an empty directory.
    $modules = [];
    $paths = \glob($directory . '*.js');
    foreach ($paths === FALSE ? [] : $paths as $path) {
      $modules[] = ProvidedModule::fromFile(
        url: $web_base . \basename($path),
        path: $path,
      );
    }

    // No cache tags: these files ship with Canvas, so they don't change
    // within a request. They're named by URL, though.
    return new ProvidedModules(
      $modules,
      (new CacheableMetadata())->addCacheContexts(['url.site']),
    );
  }

}
