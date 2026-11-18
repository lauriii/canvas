<?php

declare(strict_types=1);

namespace Drupal\canvas_headless;

use Drupal\canvas_headless\PageCache\DisallowCanvasContentApiRequests;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\PageCache\ChainRequestPolicy;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Scopes content API cache exclusions to Internal Page Cache.
 */
final class CanvasHeadlessServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('http_middleware.page_cache')) {
      return;
    }

    // The shared request policy also controls HTTP caching. Extend only the
    // middleware's policy so public content responses remain HTTP-cacheable.
    $page_cache = $container->getDefinition('http_middleware.page_cache');
    $policy = (new Definition(ChainRequestPolicy::class))
      ->addMethodCall('addPolicy', [$page_cache->getArgument(1)])
      ->addMethodCall('addPolicy', [new Reference(DisallowCanvasContentApiRequests::class)]);
    $page_cache->setArgument(1, $policy);
  }

}
