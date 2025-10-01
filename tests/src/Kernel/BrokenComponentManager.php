<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\canvas\Plugin\ComponentPluginManager;

final class BrokenComponentManager extends ComponentPluginManager implements BrokenPluginManagerInterface {

  use BrokenPluginManagerTrait;

  protected function setCachedDefinitions($definitions): array {
    return parent::setCachedDefinitions($this->removeBrokenPlugins($definitions));
  }

}
