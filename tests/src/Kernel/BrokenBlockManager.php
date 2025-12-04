<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

use Drupal\Core\Block\BlockManager;

final class BrokenBlockManager extends BlockManager implements BrokenPluginManagerInterface {

  use BrokenPluginManagerTrait;

  public function findDefinitions(): array {
    return $this->removeBrokenPlugins(parent::findDefinitions());
  }

}
