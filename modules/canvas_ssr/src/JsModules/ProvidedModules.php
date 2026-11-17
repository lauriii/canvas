<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\JsModules;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;

/**
 * One provider's modules, with the cacheability of what it read.
 *
 * AllProvidedModules caches modules for the request and drops them when one
 * of their cache tags is invalidated, for example when a push changes the
 * asset library. A list without cache tags lasts the whole request, which
 * suits a provider that reads only module files.
 *
 * The base's id is cached across requests, so it also varies by the list's
 * cache contexts. A provider that names modules by URL declares url.site,
 * since the URLs start with the site's base path.
 *
 * @see \Drupal\canvas_ssr\JsModules\AllProvidedModules::invalidateTags()
 * @see \Drupal\canvas_ssr\JsModules\BaseModules
 */
final class ProvidedModules implements \IteratorAggregate, CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * Constructs a ProvidedModules.
   *
   * @param list<\Drupal\canvas_ssr\JsModules\ProvidedModule> $modules
   *   The modules the provider supplies.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface|null $cacheability
   *   The cacheability of what the provider read, or NULL if it can't change
   *   within a request.
   */
  public function __construct(
    private readonly array $modules,
    ?CacheableDependencyInterface $cacheability = NULL,
  ) {
    if ($cacheability !== NULL) {
      $this->setCacheability($cacheability);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @return \Traversable<\Drupal\canvas_ssr\JsModules\ProvidedModule>
   *   The modules, in the order the provider supplied them.
   */
  public function getIterator(): \Traversable {
    return new \ArrayIterator($this->modules);
  }

}
