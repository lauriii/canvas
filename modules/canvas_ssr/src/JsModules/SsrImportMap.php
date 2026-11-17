<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\canvas\Render\ImportMapResponseAttachmentsProcessor;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The SSR import map: what runners resolve with.
 *
 * It's the site import map with the provided modules' specifiers added. A
 * provided module's specifier wins over the site's import map entry for it.
 * That's what puts the SSR-safe swr shim ahead of the browser bundle the
 * site import map points at.
 *
 * The site's import map entries are kept as they are, so
 * import.meta.resolve() answers with the URLs the browser gets. They reach
 * the provided modules because providers name modules by the URLs the
 * browser loads them from.
 *
 * It's rebuilt only when the site import map or the provided modules change,
 * so it's the same array until then and runners can spot a repeat with ===.
 *
 * @see \Drupal\canvas_ssr\JsModules\SiteImportMap
 * @see \Drupal\canvas_ssr\JsModules\AllProvidedModules
 * @see \Drupal\canvas_ssr\JsRunner\RunnerInterface::useBase()
 */
final class SsrImportMap implements CacheableDependencyInterface {

  /**
   * The map, once built.
   *
   * @var array<string, mixed>|null
   */
  private ?array $importMap = NULL;

  /**
   * The site import map $importMap was built from.
   *
   * @var array<string, mixed>|null
   */
  private ?array $siteFrom = NULL;

  /**
   * The specifiers $importMap was built from.
   *
   * @var array<string, string>|null
   */
  private ?array $specifiersFrom = NULL;

  /**
   * Constructs an SsrImportMap.
   *
   * @param \Drupal\canvas_ssr\JsModules\SiteImportMap $siteImportMap
   *   The site import map.
   * @param \Drupal\canvas_ssr\JsModules\AllProvidedModules $providedModules
   *   The provided modules, whose specifiers are added.
   */
  public function __construct(
    private readonly SiteImportMap $siteImportMap,
    private readonly AllProvidedModules $providedModules,
  ) {}

  /**
   * Returns the map.
   *
   * @return array<string, mixed>
   *   The map, in the shape Canvas attaches to an island. It's the same array
   *   until the site import map or the provided modules change.
   */
  public function get(): array {
    $site = $this->siteImportMap->get();
    $specifiers = $this->providedModules->specifiers();
    if ($this->importMap !== NULL && $site === $this->siteFrom && $specifiers === $this->specifiersFrom) {
      return $this->importMap;
    }
    $this->siteFrom = $site;
    $this->specifiersFrom = $specifiers;
    $site[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS] = $specifiers + $this->siteImportMap->imports();

    return $this->importMap = $site;
  }

  /**
   * Returns whether the map resolves a specifier.
   *
   * @param string $specifier
   *   The specifier, such as "preact/hooks".
   *
   * @return bool
   *   TRUE if resolve() gives a module URL for it.
   */
  public function resolves(string $specifier): bool {
    return $this->resolve($specifier) !== NULL;
  }

  /**
   * Resolves a specifier through the map, as from the page.
   *
   * @param string $specifier
   *   The specifier, such as "preact/hooks".
   *
   * @return string|null
   *   The module URL, or NULL if the specifier names none.
   *
   * @see self::resolveIn()
   */
  public function resolve(string $specifier): ?string {
    $imports = $this->get()[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS] ?? NULL;

    return self::resolveIn(\is_array($imports) ? $imports : [], $specifier);
  }

  /**
   * Resolves a specifier through an import map's imports, as from the page.
   *
   * An exact key wins, else the longest key ending in "/" that starts the
   * specifier. A key that matches but maps to nothing, or a "prefix/" key
   * whose URL lacks the trailing slash, is blocked: the specifier then
   * resolves to nothing, rather than through a less specific key. A rooted
   * path or an absolute URL no key matches resolves to itself. Scopes
   * aren't read. Runners resolve the same way.
   *
   * @param array<mixed> $imports
   *   The import map's imports.
   * @param string $specifier
   *   The specifier.
   *
   * @return string|null
   *   The module URL, or NULL if the specifier names none.
   */
  public static function resolveIn(array $imports, string $specifier): ?string {
    if (\array_key_exists($specifier, $imports)) {
      $url = $imports[$specifier];

      return \is_string($url) ? self::moduleUrl($url) : NULL;
    }
    $longest = NULL;
    foreach (\array_keys($imports) as $key) {
      $key = (string) $key;
      if (\str_ends_with($key, '/') && \str_starts_with($specifier, $key) && \strlen($key) > \strlen($longest ?? '')) {
        $longest = $key;
      }
    }
    if ($longest !== NULL) {
      $url = $imports[$longest];

      return \is_string($url) && \str_ends_with($url, '/')
        ? self::moduleUrl($url . \substr($specifier, \strlen($longest)))
        : NULL;
    }
    if (\str_starts_with($specifier, '/') || \preg_match('#^[a-zA-Z][a-zA-Z\d+\-.]*://#', $specifier) === 1) {
      return self::moduleUrl($specifier);
    }

    return NULL;
  }

  /**
   * Returns the module URL a URL loads: the URL less its query and fragment.
   *
   * @param string $url
   *   The URL.
   *
   * @return string
   *   The module URL.
   */
  public static function moduleUrl(string $url): string {
    return \preg_replace('/[?#].*$/s', '', $url) ?? $url;
  }

  /**
   * Returns whether the map has an unscoped import for a specifier.
   *
   * @param string $specifier
   *   The specifier, such as "tailwind-merge".
   *
   * @return bool
   *   TRUE if an import map entry outside any scope matches it exactly.
   */
  public function hasUnscopedImport(string $specifier): bool {
    return isset($this->get()[ImportMapResponseAttachmentsProcessor::GLOBAL_IMPORTS][$specifier]);
  }

  /**
   * Returns the cacheability of both inputs.
   */
  private function cacheability(): CacheableMetadata {
    return (new CacheableMetadata())
      ->addCacheableDependency($this->siteImportMap)
      ->addCacheableDependency($this->providedModules->getCacheability());
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
