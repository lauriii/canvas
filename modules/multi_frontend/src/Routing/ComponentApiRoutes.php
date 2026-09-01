<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Routing;

use Drupal\multi_frontend\ComponentProducerManager;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Derives one data route per producer.
 *
 * The module writes no controller and no route. A producer declaring
 * `subject: entity:media` gets `/component-api/<producer>/{media}` with the
 * matching parameter converter and `_entity_access: media.view`, so the HTTP
 * entry point inherits the entity's own access rules rather than
 * reimplementing them.
 */
final class ComponentApiRoutes {

  public function __construct(
    private readonly ComponentProducerManager $producerManager,
  ) {}

  /**
   * Route callback.
   */
  public function routes(): RouteCollection {
    $collection = new RouteCollection();
    foreach ($this->producerManager->getDefinitions() as $id => $definition) {
      $entity_type_id = ComponentProducerManager::getSubjectEntityTypeId($definition);
      if ($entity_type_id === NULL) {
        // A producer whose subject is not an entity gets no derived route and
        // is reachable only inside a page envelope. That is a supported
        // state, not a gap: it just means core has no safe generic way to
        // resolve and access-check the subject from a URL.
        continue;
      }
      $route = new Route(
        \sprintf('/component-api/%s/{%s}', $id, $entity_type_id),
        [
          '_controller' => '\Drupal\multi_frontend\Controller\ComponentApiController::component',
          'producer' => $id,
        ],
        ['_entity_access' => $entity_type_id . '.view'],
        [
          'parameters' => [$entity_type_id => ['type' => 'entity:' . $entity_type_id]],
          'no_cache' => FALSE,
        ],
        '',
        [],
        ['GET'],
      );
      $collection->add('multi_frontend.component.' . str_replace('.', '_', $id), $route);
    }
    return $collection;
  }

}
