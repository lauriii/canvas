<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Plugin\ComponentPluginManager;

/**
 * @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
 */
final class BrokenComponentManager extends ComponentPluginManager implements BrokenPluginManagerInterface {

  use BrokenPluginManagerTrait;

  public function findDefinitions(): array {
    return $this->removeBrokenPlugins(parent::findDefinitions());
  }

}
