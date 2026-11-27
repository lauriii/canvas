<?php

declare(strict_types=1);

namespace Drupal\canvas_style_polyfill;

use Drupal\canvas\Style\StyleSourceInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Provides Canvas' style source when core has no Style API.
 *
 * UPSTREAM(core): once Canvas requires a core version with the Style API,
 * delete this module (and add an update hook that uninstalls it).
 *
 * @see https://www.drupal.org/project/drupal/issues/3517033
 * @see \Drupal\canvas\CanvasServiceProvider::alter()
 */
final class CanvasStylePolyfillServiceProvider implements ServiceModifierInterface {

  public function alter(ContainerBuilder $container): void {
    // Core's Style API wins: Canvas then registers CoreStyleSource itself.
    if ($container->hasDefinition('plugin.manager.style')) {
      return;
    }
    $container->register(StyleSourceInterface::class, PolyfillStyleSource::class)
      ->setArguments([
        new Reference('module_handler'),
        new Reference('theme_handler'),
        new Reference('config.factory'),
        new Reference('cache.discovery'),
      ]);
  }

}
