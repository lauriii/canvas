<?php

declare(strict_types=1);

namespace Drupal\canvas\Config;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Core\Theme\ThemeInitializationInterface;

/**
 * Discovers and loads Canvas theme configuration from YAML files.
 *
 * Themes can define Canvas settings in {theme}.canvas.yml files. This service
 * discovers these files, handles theme inheritance, and merges configurations
 * (child themes override parent themes).
 */
final class ThemeSettingsDiscovery {

  /**
   * Static cache of discovered settings.
   *
   * @var array<string, array>
   */
  private static array $cache = [];

  /**
   * Constructs a ThemeSettingsDiscovery object.
   */
  public function __construct(
    private readonly ThemeInitializationInterface $themeInitialization,
    private readonly string $appRoot,
    private readonly CacheBackendInterface $cacheBackend,
  ) {
  }

  /**
   * Gets the merged Canvas settings for a specific theme.
   *
   * This method follows the theme inheritance chain (base themes → active
   * theme) and merges configurations, with child themes overriding parent
   * themes.
   *
   * @param string $theme_name
   *   The theme name to get settings for.
   *
   * @return array
   *   The merged Canvas settings array.
   */
  public function getSettings(string $theme_name): array {
    // Use static cache to avoid re-discovering on the same request.
    if (isset(self::$cache[$theme_name])) {
      return self::$cache[$theme_name];
    }

    // Try to load from persistent cache.
    $cache_key = "canvas:theme_settings:{$theme_name}";
    $cached = $this->cacheBackend->get($cache_key);
    if ($cached && $cached->data) {
      self::$cache[$theme_name] = $cached->data;
      return $cached->data;
    }

    try {
      $active_theme = $this->themeInitialization->getActiveThemeByName($theme_name);
    }
    catch (\Exception) {
      // Theme doesn't exist or has missing dependencies.
      $empty_result = [];
      self::$cache[$theme_name] = $empty_result;
      // Cache empty result to avoid re-checking on every request.
      $this->cacheBackend->set(
        $cache_key,
        $empty_result,
        CacheBackendInterface::CACHE_PERMANENT,
        ['config:core.extension']
      );
      return $empty_result;
    }

    // Build theme chain from base themes (first) to active theme (last).
    $theme_chain = [];
    $theme_extensions = [];

    // Add base themes first (in order from root to parent).
    foreach ($active_theme->getBaseThemeExtensions() as $base_theme_extension) {
      $theme_chain[] = $base_theme_extension->getName();
      $theme_extensions[$base_theme_extension->getName()] = $base_theme_extension;
    }

    // Add the active theme last.
    $main_theme_extension = $active_theme->getExtension();
    $theme_chain[] = $main_theme_extension->getName();
    $theme_extensions[$main_theme_extension->getName()] = $main_theme_extension;

    // Get theme directories for discovery.
    // Prepend Drupal root to make paths absolute for file_exists().
    $theme_directories = [];
    foreach ($theme_extensions as $theme_name_key => $extension) {
      $theme_path = $extension->getPath();
      // Normalize path to avoid double slashes.
      $theme_directories[$theme_name_key] = rtrim($this->appRoot, '/') . '/' .
        ltrim($theme_path, '/');
    }

    if (count($theme_directories) === 0) {
      self::$cache[$theme_name] = [];
      return [];
    }

    // Discover YAML files using YamlDiscovery.
    $yaml_discovery = new YamlDiscovery('canvas', $theme_directories);
    $definitions = $yaml_discovery->findAll();

    // Merge configurations in inheritance order (base themes first, then
    // child).
    $merged_settings = [];
    foreach ($theme_chain as $theme) {
      if (isset($definitions[$theme])) {
        $theme_config = $definitions[$theme];
        // Validate and filter viewport settings if present.
        if (isset($theme_config['viewports']) && is_array($theme_config['viewports'])) {
          $theme_config['viewports'] = $this->validateViewports($theme_config['viewports']);
        }
        $merged_settings = NestedArray::mergeDeepArray(
          [$merged_settings, $theme_config],
          TRUE
        );
      }
    }

    // Ensure viewports key exists if it was set (even if empty after
    // validation). This allows the Controller to always access
    // $theme_settings['viewports'].
    if (isset($merged_settings['viewports']) &&
      empty($merged_settings['viewports'])) {
      // Remove empty viewports array to fall back to defaults on client side.
      unset($merged_settings['viewports']);
    }

    // Store in static cache for this request.
    self::$cache[$theme_name] = $merged_settings;

    $this->cacheBackend->set(
      $cache_key,
      $merged_settings,
      CacheBackendInterface::CACHE_PERMANENT,
      ['config:core.extension']
    );

    return $merged_settings;
  }

  /**
   * Validates viewport configuration values.
   *
   * Ensures all viewport widths are positive integers. Invalid values are
   * filtered out.
   *
   * @param array $viewports
   *   Array of viewport configurations keyed by viewport ID.
   *
   * @return array
   *   Validated viewport configuration with invalid entries removed.
   */
  private function validateViewports(array $viewports): array {
    $validated = [];
    foreach ($viewports as $id => $width) {
      // Only accept positive integers.
      if (is_int($width) && $width > 0) {
        $validated[$id] = $width;
      }
      // Also accept numeric strings that represent positive integers.
      elseif (is_string($width) && ctype_digit($width) && (int) $width > 0) {
        $validated[$id] = (int) $width;
      }
    }
    return $validated;
  }

}
