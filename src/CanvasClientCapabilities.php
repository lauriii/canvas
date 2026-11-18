<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Symfony\Component\HttpFoundation\Request;

/**
 * Determines which capabilities the requesting client supports.
 *
 * Server capabilities are advertised separately in the 'capabilities' key of
 * /canvas/api/v0/site-data.
 *
 * @see \Drupal\canvas\Controller\SiteDataController::get()
 */
final class CanvasClientCapabilities {

  public const CAPABILITIES_HEADER = 'Canvas-Client-Capabilities';

  /**
   * Whether the requesting client advertises the given capability.
   *
   * Capability tokens are case-insensitive and comma-separated. Empty members
   * never match; spaces and tabs around advertised tokens are ignored.
   */
  public static function has(Request $request, string $capability): bool {
    if ($capability === '') {
      return FALSE;
    }
    foreach ($request->headers->all(self::CAPABILITIES_HEADER) as $value) {
      if ($value === NULL) {
        continue;
      }
      foreach (\explode(',', $value) as $token) {
        if (\strcasecmp(\trim($token, " \t"), $capability) === 0) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
