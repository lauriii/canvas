<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\Component\Utility\UrlHelper;
use GuzzleHttp\Psr7\Uri;
use Symfony\Component\HttpFoundation\Request;

/**
 * Normalizes generated absolute URLs to content API request URIs.
 */
final class CanvasContentUrl {

  /**
   * Rewrites same-site absolute URLs, preserving external and relative URLs.
   */
  public static function normalize(string $url, Request $request): string {
    if (!UrlHelper::isExternal($url)) {
      return $url;
    }

    $base_url = $request->getSchemeAndHttpHost() . $request->getBasePath() . '/';
    if (!UrlHelper::externalIsLocal($url, $base_url)) {
      return $url;
    }

    $parts = parse_url($url);
    if (!\is_array($parts)) {
      return $url;
    }

    return (string) Uri::fromParts([
      'path' => self::stripBasePath((string) ($parts['path'] ?? '/'), $request->getBasePath()),
      'query' => $parts['query'] ?? '',
      'fragment' => $parts['fragment'] ?? '',
    ]);
  }

  /**
   * Removes the Drupal base path from a root-relative path.
   */
  private static function stripBasePath(string $path, string $base_path): string {
    if ($base_path === '') {
      return $path;
    }

    return match (TRUE) {
      $path === $base_path => '/',
      str_starts_with($path, $base_path . '/') => substr($path, strlen($base_path)),
      default => $path,
    };
  }

}
