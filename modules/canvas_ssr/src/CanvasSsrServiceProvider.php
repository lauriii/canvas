<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr;

use Drupal\canvas_ssr\Fetch\Marshaler\FetchMarshaler;
use Drupal\canvas_ssr\Fetch\Policy\JsonApiPathPolicy;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Adds what canvas_ssr's services need from outside the container.
 *
 * The JSON:API fetch policy is registered only when jsonapi is enabled. It
 * needs jsonapi's "jsonapi.base_path" parameter, and canvas_ssr doesn't
 * depend on jsonapi.
 *
 * The fetch marshaler's JS is read here, so it's stored with the container
 * and no request reads its files. Changing them needs a cache rebuild.
 */
final class CanvasSsrServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (!\is_array($modules) || !isset($modules['jsonapi'])) {
      return;
    }

    $definition = new Definition(JsonApiPathPolicy::class, ['%jsonapi.base_path%']);
    $definition->addTag('canvas_ssr.path_policy');
    $container->setDefinition(JsonApiPathPolicy::class, $definition);
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $container
      ->getDefinition(FetchMarshaler::class)
      ->setArguments(FetchMarshaler::readJs());
  }

}
