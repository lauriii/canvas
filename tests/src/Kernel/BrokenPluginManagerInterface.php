<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel;

interface BrokenPluginManagerInterface {

  public function markPluginAsMissing(string $pluginId): void;

}
