<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\canvas_ssr\JsModules\Provider\ModuleProviderInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * A provider whose modules can change, with cache tags for when they do.
 */
final class AllProvidedModulesMutableProvider implements ModuleProviderInterface {

  /**
   * Number of times getModules() has been asked.
   */
  public int $asked = 0;

  /**
   * Constructs a mutable provider.
   *
   * @param list<\Drupal\canvas_ssr\JsModules\ProvidedModule> $modules
   *   Modules to return until they are replaced.
   * @param string[] $cacheTags
   *   Cache tags standing for whatever the provider read to build them.
   */
  public function __construct(
    public array $modules,
    private readonly array $cacheTags,
  ) {}

  public function getModules(): ProvidedModules {
    $this->asked++;
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags($this->cacheTags);

    return new ProvidedModules($this->modules, $cacheability);
  }

}
