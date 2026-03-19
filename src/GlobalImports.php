<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Entity\AssetLibrary;
use Drupal\Core\Asset\AssetQueryStringInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * @internal
 */
class GlobalImports {

  public function __construct(
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly Version $version,
    private readonly AssetQueryStringInterface $assetQueryString,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeManagerInterface $themeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * @return array{imports: array<string, string>, scopes: array<string, array<string, string>>}
   */
  public function getImportMap(): array {
    $base_path = \base_path();
    $canvas_path = $this->extensionPathResolver->getPath('module', 'canvas');

    // Build base import map.
    // Whenever updating this import map, also update the list of supported
    // imports in `packages/eslint-config/src/rules/component-imports.ts`.
    // @see https://drupal.org/i/3560197
    $import_map = [
      ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS => [
        'preact' => \sprintf('%s%s/packages/astro-hydration/dist/preact.module.js', $base_path, $canvas_path),
        'preact/hooks' => \sprintf('%s%s/packages/astro-hydration/dist/hooks.module.js', $base_path, $canvas_path),
        'react/jsx-runtime' => \sprintf('%s%s/packages/astro-hydration/dist/jsx-runtime-default.js', $base_path, $canvas_path),
        'react' => \sprintf('%s%s/packages/astro-hydration/dist/compat.module.js', $base_path, $canvas_path),
        'react-dom' => \sprintf('%s%s/packages/astro-hydration/dist/compat.module.js', $base_path, $canvas_path),
        'react-dom/client' => \sprintf('%s%s/packages/astro-hydration/dist/compat.module.js', $base_path, $canvas_path),
        'clsx' => \sprintf('%s%s/packages/astro-hydration/dist/clsx.js', $base_path, $canvas_path),
        'class-variance-authority' => \sprintf('%s%s/packages/astro-hydration/dist/class-variance-authority.js', $base_path, $canvas_path),
        'tailwind-merge' => \sprintf('%s%s/packages/astro-hydration/dist/tailwind-merge.js', $base_path, $canvas_path),
        'drupal-jsonapi-params' => \sprintf('%s%s/packages/astro-hydration/dist/jsonapi-params.js', $base_path, $canvas_path),
        'swr' => \sprintf('%s%s/packages/astro-hydration/dist/swr.js', $base_path, $canvas_path),
        '@tailwindcss/typography' => \sprintf('%s%s/packages/astro-hydration/dist/tailwindcss-typography.js', $base_path, $canvas_path),

        'drupal-canvas' => \sprintf('%s%s/packages/astro-hydration/dist/drupal-canvas.js', $base_path, $canvas_path),
        // Backward compatibility entries for elements that were moved
        // into drupal-canvas package.
        '@/lib/FormattedText' => \sprintf('%s%s/packages/astro-hydration/dist/FormattedText.js', $base_path, $canvas_path),
        'next-image-standalone' => \sprintf('%s%s/packages/astro-hydration/dist/next-image-standalone.js', $base_path, $canvas_path),
        '@/lib/utils' => \sprintf('%s%s/packages/astro-hydration/dist/utils.js', $base_path, $canvas_path),
        '@drupal-api-client/json-api-client' => \sprintf('%s%s/packages/astro-hydration/dist/jsonapi-client.js', $base_path, $canvas_path),
        '@/lib/jsonapi-utils' => \sprintf('%s%s/packages/astro-hydration/dist/jsonapi-utils.js', $base_path, $canvas_path),
        '@/lib/drupal-utils' => \sprintf('%s%s/packages/astro-hydration/dist/drupal-utils.js', $base_path, $canvas_path),
      ],
      ImportMapResponseAttachmentsProcessor::SCOPED_IMPORTS => [],
    ];

    // Add entries from the asset library manifest (pushed via CLI).
    // Manifest entries are appended after base imports, so a manifest entry
    // with the same name as a base import (e.g. "react") will override it.
    // This is intentional: it allows vendor-bundled builds to replace the
    // default module-shipped libraries.
    $asset_library = AssetLibrary::load(AssetLibrary::GLOBAL_ID);
    if ($asset_library !== NULL) {
      foreach ($asset_library->getImports() as $entry) {
        $import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS][$entry['name']] = $this->fileUrlGenerator->generateString($entry['uri']);
      }
      foreach ($asset_library->getAssets() as $entry) {
        $import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS][$entry['name']] = $this->fileUrlGenerator->generateString($entry['uri']);
      }
    }

    // Allow modules and themes to alter the import map.
    // @todo Deprecate hook_canvas_importmap_alter() in favor of Drupal core's hook_importmap_alter() when https://www.drupal.org/i/3398525 lands.
    // @see hook_canvas_importmap_alter()
    $this->moduleHandler->alter('canvas_importmap', $import_map);
    $this->themeManager->alter('canvas_importmap', $import_map);

    // We need a cache-busting query string for the browser to not use cached
    // files after installing an update.
    $query_string = $this->getQueryString();
    foreach ($import_map[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS] as &$asset) {
      $asset .= '?' . $query_string;
    }
    return $import_map;
  }

  public function getQueryString(): string {
    $version = $this->version->getVersion();
    // If version is 0.0.0, use the AssetQueryStringInterface service to improve
    // DX: avoid the need to do a hard refresh or wipe the browser cache.
    return $version === '0.0.0' ? $this->assetQueryString->get() : $version;
  }

}
