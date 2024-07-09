<?php

declare(strict_types=1);

namespace Drupal\Tests\experience_builder\Traits;

use Drupal\Core\DependencyInjection\ContainerBuilder;

trait ContribStrictConfigSchemaTestTrait {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Opt kernel test in to strict config schema checking, even though this is
    // a contrib module.
    $container->getDefinition('testing.config_schema_checker')->setArgument(2, TRUE);
  }

}
