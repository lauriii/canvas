<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

class ExperienceBuilderServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    assert(is_array($modules));
    if (array_key_exists('media_library', $modules)) {
      $container->register('experience_builder.media_library.opener', MediaLibraryXbPropOpener::class)
        ->addTag('media_library.opener');
    }
  }

}
