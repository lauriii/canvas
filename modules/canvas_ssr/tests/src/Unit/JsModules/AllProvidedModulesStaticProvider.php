<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsModules;

use Drupal\canvas_ssr\JsModules\ProvidedModules;
use Drupal\canvas_ssr\JsModules\Provider\ModuleProviderInterface;

/**
 * A provider whose modules never change.
 */
final readonly class AllProvidedModulesStaticProvider implements ModuleProviderInterface {

  /**
   * Constructs a static provider for test modules.
   *
   * @param list<\Drupal\canvas_ssr\JsModules\ProvidedModule> $modules
   *   Modules to return from getModules().
   */
  public function __construct(
    private array $modules,
  ) {}

  public function getModules(): ProvidedModules {
    return new ProvidedModules($this->modules);
  }

}
