<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\canvas_ssr\JsRunner\ModuleUrl;

/**
 * A provided module.
 *
 * It has its module URL, how to read its source, and the specifiers that
 * resolve to it.
 *
 * Most take the URL the browser loads them from, which the site import map
 * already reaches. An SSR-only module takes a path under "/_canvas_ssr/"
 * instead, and names the specifiers that resolve to it.
 * SsrImportMap adds those to the SSR import map, ahead of the site's import
 * map entries for them, which is how an SSR-only module replaces the one the
 * browser gets.
 */
final readonly class ProvidedModule {

  /**
   * Constructs a ProvidedModule.
   *
   * @param string $url
   *   The module URL: a rooted path, e.g.
   *   "/modules/contrib/canvas/packages/astro-hydration/dist/preact.js", or
   *   an absolute URL, as from a CDN.
   * @param \Closure(): ?string $read
   *   Returns the source, or NULL if it can't be read. It's called only when
   *   a render needs the source, so it can be costly.
   * @param list<string> $specifiers
   *   Bare specifiers that resolve to this module, e.g. "swr".
   */
  public function __construct(
    public string $url,
    private \Closure $read,
    public array $specifiers = [],
  ) {
    ModuleUrl::assert($url);
  }

  /**
   * Constructs a ProvidedModule whose source is in a file.
   *
   * The file is read once, the first time its source is needed.
   *
   * @param string $url
   *   The module URL.
   * @param string $path
   *   The file.
   * @param list<string> $specifiers
   *   Bare specifiers that resolve to this module.
   *
   * @return self
   *   The module.
   */
  public static function fromFile(string $url, string $path, array $specifiers = []): self {
    $source = NULL;
    $read = static function () use ($path, &$source): ?string {
      $source ??= \is_file($path) ? \file_get_contents($path) : FALSE;
      return $source === FALSE ? NULL : $source;
    };

    return new self($url, $read, $specifiers);
  }

  /**
   * Returns the source.
   *
   * @return string|null
   *   The source, or NULL if it can't be read.
   */
  public function source(): ?string {
    return ($this->read)();
  }

}
