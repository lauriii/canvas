<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules\Provider;

use Drupal\canvas_ssr\JsModules\ProvidedModule;
use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\canvas_ssr\JsModules\TamedProperties;
use Drupal\Core\Extension\ExtensionPathResolver;

/**
 * Supplies the SSR-only modules.
 *
 * - preact-render-to-string, which the browser never loads.
 * - The realm setup module, which every main module imports first, and
 *   which locks the realm before any component runs.
 * - The framework renderers in js/renderers/. A component's main module
 *   imports the one for the island's framework.
 * - The tailwind-merge warm-up, which BaseModules adds as a preload module.
 * - A swr shim. Real swr fetches in useEffect, which never runs during
 *   SSR. A provided module's specifier wins over the site's import map
 *   entry for it, so claiming "swr" here replaces the real one.
 * - The hydration data module, through which a library such as the swr
 *   shim gives a hydrated island's first render in the browser the state
 *   it rendered with on the server.
 *
 * The browser never loads these, so they take module URLs under
 * PATH_PREFIX, where Drupal serves nothing.
 *
 * This provider has the highest priority, so it's the place to replace any
 * other provider's module that doesn't work under SSR.
 *
 * @see \Drupal\canvas_ssr\JsModules\Provider\CanvasDistModuleProvider
 * @see \Drupal\canvas_ssr\JsModules\Provider\CanvasPushedManifestModuleProvider
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface
 * @see \Drupal\canvas_ssr\Renderer
 */
final readonly class SsrOnlyModuleProvider implements ModuleProviderInterface {

  /**
   * The path the module URLs start with.
   */
  public const string PATH_PREFIX = '/_canvas_ssr/ssr-only/';

  /**
   * Prefix for the framework renderers' specifiers.
   *
   * A component's main module imports one by this, so it loads through the
   * SSR import map like any other import. Renderer bails for an island
   * whose framework's specifier isn't in that map.
   */
  private const string RENDERER_SPECIFIER_PREFIX = '@/canvas_ssr/renderer/';

  /**
   * Returns a framework renderer's specifier.
   *
   * @param string $framework
   *   The framework, such as "preact".
   *
   * @return string
   *   The specifier, whether or not the framework has a renderer.
   */
  public static function rendererSpecifier(string $framework): string {
    return self::RENDERER_SPECIFIER_PREFIX . $framework;
  }

  /**
   * The renderers' directory, relative to the module.
   */
  private const RENDERER_DIRECTORY = '/js/renderers/';

  /**
   * The realm setup module's specifier, which main modules import it by.
   */
  public const string REALM_SETUP_SPECIFIER = '@/canvas_ssr/realm-setup';

  /**
   * The realm setup module's path.
   *
   * @see \Drupal\canvas_ssr\JsModules\BaseModules
   */
  public const string REALM_SETUP_PATH = self::PATH_PREFIX . 'realm-setup.js';

  /**
   * The hydration data module's specifier.
   *
   * Main modules import it, and every island's libraries module evaluates
   * it with the framework renderer.
   *
   * @see \Drupal\canvas_ssr\ComponentInfo\MainModule
   */
  public const string HYDRATION_DATA_SPECIFIER = '@/canvas_ssr/hydration-data';

  /**
   * What the realm setup module's source has where the tamed globals go.
   */
  private const string TAMED_GLOBALS_PLACEHOLDER = '{} /* tamed globals */';

  /**
   * The tailwind-merge warm-up's path.
   *
   * @see \Drupal\canvas_ssr\JsModules\BaseModules
   */
  public const string TW_MERGE_WARMUP_PATH = self::PATH_PREFIX . 'tw-merge-warmup.js';

  public function __construct(
    private ExtensionPathResolver $extensionPathResolver,
    private TamedProperties $tamedProperties,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getModules(): ProvidedModules {
    $ssr_root = DRUPAL_ROOT . '/' . $this->extensionPathResolver->getPath('module', 'canvas_ssr');
    $dist = DRUPAL_ROOT . '/' . $this->extensionPathResolver->getPath('module', 'canvas') . CanvasDistModuleProvider::DIST_DIRECTORY;

    $setup_path = $ssr_root . '/js/realm-setup.js';
    $tamed_globals = $this->tamedProperties->globals();
    $modules = [
      new ProvidedModule(
        url: self::REALM_SETUP_PATH,
        // Taming is configured, so the source carries it. Changing it
        // changes the base, which runners rebuild from.
        read: static function () use ($setup_path, $tamed_globals): ?string {
          $source = \is_file($setup_path) ? \file_get_contents($setup_path) : FALSE;
          if ($source === FALSE) {
            return NULL;
          }
          $json = \json_encode((object) $tamed_globals, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
          return \str_replace(self::TAMED_GLOBALS_PLACEHOLDER, $json, $source);
        },
        specifiers: [self::REALM_SETUP_SPECIFIER],
      ),
      ProvidedModule::fromFile(
        url: self::PATH_PREFIX . 'preact-render-to-string.js',
        path: $dist . 'preact-render-to-string.js',
        specifiers: ['preact-render-to-string'],
      ),
    ];

    // Each file is named for its framework, so adding a framework renderer
    // is adding a file.
    $renderer_directory = $ssr_root . self::RENDERER_DIRECTORY;
    $renderer_paths = \glob($renderer_directory . '*.js');
    foreach ($renderer_paths === FALSE ? [] : $renderer_paths as $renderer_path) {
      $framework = \basename($renderer_path, '.js');
      $modules[] = ProvidedModule::fromFile(
        url: self::PATH_PREFIX . 'renderers/' . $framework . '.js',
        path: $renderer_path,
        specifiers: [self::rendererSpecifier($framework)],
      );
    }

    $modules[] = ProvidedModule::fromFile(
      url: self::PATH_PREFIX . 'hydration-data.js',
      path: $ssr_root . '/js/hydration-data.js',
      specifiers: [self::HYDRATION_DATA_SPECIFIER],
    );

    $modules[] = ProvidedModule::fromFile(
      url: self::PATH_PREFIX . 'swr-shim.js',
      path: $ssr_root . '/js/swr-shim.js',
      specifiers: ['swr'],
    );

    $modules[] = ProvidedModule::fromFile(
      url: self::TW_MERGE_WARMUP_PATH,
      path: $ssr_root . '/js/tw-merge-warmup.js',
    );

    // No cacheability: these files ship with modules, so they don't change
    // within a request.
    return new ProvidedModules($modules);
  }

}
