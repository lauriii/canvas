<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Traits;

use Drupal\canvas\ComponentSource\ComponentSourceManager;

trait GenerateComponentConfigTrait {

  protected function generateComponentConfig(): void {
    // @todo Remove this trait in https://www.drupal.org/i/3561270.
    $this->container->get(ComponentSourceManager::class)->generateComponents();
  }

}
