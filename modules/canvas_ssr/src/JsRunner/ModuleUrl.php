<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsRunner;

/**
 * What a module may be registered under.
 *
 * A module URL is the URL the browser loads the module from: a rooted path,
 * starting with "/", or an absolute URL, like a CDN's. One starting "//" is
 * protocol-relative, as some CDN modules write them, so its host is its
 * origin. Runners take URLs as they are, and a runner that loads modules
 * from elsewhere maps them itself.
 *
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::addModule()
 */
final class ModuleUrl {

  /**
   * Returns whether a string is a module URL.
   *
   * @param string $url
   *   The string.
   *
   * @return bool
   *   TRUE if it's a rooted path or an absolute URL.
   */
  public static function is(string $url): bool {
    return \preg_match('#^(?:/|[a-zA-Z][a-zA-Z\d+\-.]*://)#', $url) === 1;
  }

  /**
   * Throws unless a string is a module URL.
   *
   * @param string $url
   *   The string.
   *
   * @throws \InvalidArgumentException
   *   If it isn't a rooted path or an absolute URL.
   */
  public static function assert(string $url): void {
    if (!self::is($url)) {
      throw new \InvalidArgumentException(\sprintf('Module URL %s must be a rooted path or an absolute URL.', $url));
    }
  }

}
