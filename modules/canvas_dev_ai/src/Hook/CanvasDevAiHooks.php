<?php

declare(strict_types=1);

namespace Drupal\canvas_dev_ai\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_dev_ai.
 *
 * @internal
 */
class CanvasDevAiHooks {

  /**
   * Implements hook_js_settings_alter().
   */
  #[Hook('js_settings_alter')]
  public static function jsSettingsAlter(array &$settings): void {
    if (!empty($settings['canvas']['aiExtensionAvailable'])) {
      $settings['canvas']['aiDevMode'] = TRUE;
    }
  }

}
