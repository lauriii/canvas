<?php

declare(strict_types=1);

namespace Drupal\canvas_headless_test\Hook;

use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for canvas_headless tests.
 */
class CanvasHeadlessTestHooks {

  /**
   * Implements hook_canvas_headless_safe_permissions().
   *
   * Declares the preview permission itself preview-safe. No real module
   * should do this — it is exactly the permission whose absence on tokens
   * keeps a preview token from minting fresh assertions. Tests enable this
   * module to prove the minting routes reject bearer tokens by
   * authentication method, not merely because the ceiling withholds the
   * permission.
   */
  #[Hook('canvas_headless_safe_permissions')]
  public static function safePermissions(): array {
    return [PreviewUrlGeneratorInterface::PREVIEW_PERMISSION];
  }

}
