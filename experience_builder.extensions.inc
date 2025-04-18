<?php

/**
 * @file
 * Hook implementations for Experience Builder extensions.
 */

use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Core\Url;

/**
 * Adds libraries that extend Experience Builder to a centralized library.
 *
 * @param array $libraries
 *   The libraries array.
 */
function _experience_builder_build_extension_libraries(array &$libraries): void {
  $core_discovery = new YamlDiscovery('libraries', \Drupal::service('module_handler')->getModuleDirectories());

  $xb_extensions = [];

  // Get all libraries with xbExtension defined in drupalSettings.
  foreach ($core_discovery->findAll() as $module_name => $module_libraries) {
    foreach ($module_libraries as $library_name => $library) {
      if (isset($library['drupalSettings']['xbExtension'])) {
        $xb_extensions[] = $module_name . '/' . $library_name;
      }
    }
  }

  // Add the libraries as dependencies of experience_builder/extensions.
  if (!empty($xb_extensions)) {
    $libraries['extensions'] = [
      'dependencies' => $xb_extensions,
    ];
  }
}

/**
 * Process module paths for all extensions.
 *
 * This is called from experience_builder_library_info_alter()
 * in the main module file.
 */
function _experience_builder_process_extensions_paths(array &$libraries, string $extension): void {
  $module_path_service = \Drupal::service('extension.list.module');

  // Find all libraries that specify xbExtension in drupalSettings and provide
  // default values and image paths.
  foreach ($libraries as &$library) {
    if (!isset($library['drupalSettings']['xbExtension'])) {
      continue;
    }

    foreach ($library['drupalSettings']['xbExtension'] as &$extension_settings) {
      $module_path = $module_path_service->getPath($extension);
      $extension_settings['modulePath'] = $module_path;

      assert(!empty($extension_settings['id']), "The xbExtension config in $extension must have an 'id' property.");
      assert(!empty($extension_settings['name']), "The xbExtension config in $extension must have a 'name' property.");

      if (empty($extension_settings['description'])) {
        $extension_settings['description'] = t('No description provided.');
      }

      if (!isset($extension_settings['imgSrc'])) {
        continue;
      }

      $img_src = $extension_settings['imgSrc'];

      // Only prepend the path if it's a relative path without a leading slash
      if (!str_starts_with($img_src, '/') && !str_starts_with($img_src, 'http')) {
        assert(!str_starts_with($img_src, '.'), 'The extension image path must not start with "."');
        $extension_settings['imgSrc'] = Url::fromUri('base://' . $module_path . '/' . $img_src)->toString();
      }
    }
  }
}
