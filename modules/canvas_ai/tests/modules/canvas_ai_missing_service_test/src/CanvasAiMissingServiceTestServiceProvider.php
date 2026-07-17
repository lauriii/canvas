<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_missing_service_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Simulates a partial or version-mismatched AI stack install.
 *
 * Removes a service that
 * \Drupal\canvas_ai\Controller\CanvasBuilder injects, reproducing the condition
 * behind issue #3550891: when any injected AI service is absent, resolving that
 * controller throws a ServiceNotFoundException, which Drupal's controller
 * resolver reports as "The controller ... is not callable".
 */
final class CanvasAiMissingServiceTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    // This service is only consumed by CanvasBuilder::create(), so removing it
    // is safe for container compilation while still breaking that controller.
    if ($container->hasDefinition('ai_agents.agent_status_poller')) {
      $container->removeDefinition('ai_agents.agent_status_poller');
    }
  }

}
