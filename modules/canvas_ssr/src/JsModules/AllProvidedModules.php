<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Merges every module provider's modules.
 *
 * Providers come in tag priority order, and for each URL or specifier the
 * first provider wins. So SsrOnlyModuleProvider, the highest, can replace a
 * module that doesn't work under SSR.
 *
 * Asking the providers is expensive, so the merged result is cached for the
 * request. It's dropped when a cache tag the providers declared is
 * invalidated, which is why this is a cache tags invalidator.
 *
 * @see \Drupal\canvas_ssr\JsModules\SsrImportMap
 */
final class AllProvidedModules implements CacheTagsInvalidatorInterface {

  /**
   * The winning module for each URL, once merged.
   *
   * @var array<string, \Drupal\canvas_ssr\JsModules\ProvidedModule>|null
   */
  private ?array $mergedModules = NULL;

  /**
   * The provided modules' specifiers, as specifier to URL.
   *
   * NULL whenever $mergedModules is.
   *
   * @var array<string, string>|null
   */
  private ?array $specifiers = NULL;

  /**
   * The providers' cacheability. NULL whenever $mergedModules is.
   */
  private ?CacheableMetadata $cacheability = NULL;

  /**
   * Constructs an AllProvidedModules.
   *
   * @param iterable<\Drupal\canvas_ssr\JsModules\Provider\ModuleProviderInterface> $providers
   *   The providers, highest priority first.
   */
  public function __construct(
    private readonly iterable $providers,
  ) {}

  /**
   * Returns the provided modules' sources, keyed by module URL.
   *
   * @return array<string, string>
   *   The sources. A module whose source can't be loaded is left out, so
   *   importing it fails.
   */
  public function sources(): array {
    $sources = [];
    foreach ($this->mergedModules() as $url => $module) {
      $source = $module->source();
      if ($source !== NULL) {
        $sources[$url] = $source;
      }
    }

    return $sources;
  }

  /**
   * Returns the specifiers the provided modules claim, as specifier to URL.
   *
   * SsrImportMap adds them to the site import map, ahead of the site's
   * import map entries.
   *
   * @return array<string, string>
   *   The specifiers. The same array until the providers' modules change.
   */
  public function specifiers(): array {
    $this->mergedModules();

    return $this->specifiers ?? [];
  }

  /**
   * Returns the cacheability of what the providers read.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheability. Its contexts are those the base varies by.
   */
  public function getCacheability(): CacheableMetadata {
    $this->mergedModules();
    \assert($this->cacheability !== NULL);

    return $this->cacheability;
  }

  /**
   * Drops the cached provider results when their tags are invalidated.
   *
   * @param string[] $tags
   *   The cache tags being invalidated.
   */
  public function invalidateTags(array $tags): void {
    // Nothing cached yet. Every request that invalidates a tag builds this
    // service, so don't query the providers just to find their tags.
    if ($this->cacheability === NULL) {
      return;
    }
    if (\array_intersect($tags, $this->cacheability->getCacheTags()) === []) {
      return;
    }

    $this->mergedModules = NULL;
    $this->specifiers = NULL;
    $this->cacheability = NULL;
  }

  /**
   * Merges the providers' modules and specifiers.
   *
   * The first provider wins each URL and each specifier.
   *
   * @return array<string, \Drupal\canvas_ssr\JsModules\ProvidedModule>
   *   The winning module keyed by module URL.
   */
  private function mergedModules(): array {
    if ($this->mergedModules !== NULL) {
      return $this->mergedModules;
    }
    $modules = [];
    $specifiers = [];
    // Include providers that returned nothing, since that result can change
    // too.
    $cacheability = new CacheableMetadata();
    foreach ($this->providers as $provider) {
      $list = $provider->getModules();
      $cacheability->addCacheableDependency($list);
      foreach ($list as $module) {
        $modules[$module->url] ??= $module;
        foreach ($module->specifiers as $specifier) {
          $specifiers[$specifier] ??= $module->url;
        }
      }
    }
    $this->cacheability = $cacheability;
    $this->specifiers = $specifiers;

    return $this->mergedModules = $modules;
  }

}
