<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\multi_frontend\ComponentProducerManager;
use Drupal\multi_frontend\Envelope\CacheabilityHeaders;
use Drupal\multi_frontend\ProducerInvoker;
use Drupal\multi_frontend\Schema\SchemaPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves one component's props, and the published schemas.
 *
 * The data route is derived from the producer definition rather than written
 * by the module. That is the whole point of the producer registry: core can
 * go from a producer to an HTTP endpoint without a call site naming it, which
 * is what stops every module inventing its own JSON shape and URL.
 */
final class ComponentApiController extends ControllerBase {

  public function __construct(
    private readonly ProducerInvoker $invoker,
    private readonly ComponentProducerManager $producerManager,
    private readonly SchemaPublisher $schemaPublisher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(ProducerInvoker::class),
      $container->get(ComponentProducerManager::class),
      $container->get(SchemaPublisher::class),
    );
  }

  /**
   * Serves one produced component node.
   *
   * The subject comes from the route's own parameter conversion, and the
   * route carries the entity's view-access requirement, so this endpoint can
   * never be laxer than the entity's own rules.
   */
  public function component(RouteMatchInterface $route_match, string $producer): CacheableJsonResponse {
    $definition = $this->producerManager->getDefinition($producer, FALSE);
    if ($definition === NULL) {
      throw new NotFoundHttpException();
    }
    $entity_type_id = ComponentProducerManager::getSubjectEntityTypeId($definition);
    $subject = $entity_type_id === NULL ? NULL : $route_match->getParameter($entity_type_id);
    if ($subject === NULL) {
      throw new NotFoundHttpException();
    }

    // The node's own cacheability stays in the body, so that a component
    // fetched on its own is byte for byte the same as the same node read from
    // a page envelope. The response's union, which additionally includes what
    // the route's access check varied on, goes in the headers, which is where
    // a shared cache reads it.
    $node_cacheability = new CacheableMetadata();
    $node = $this->invoker->produceNode($producer, $subject, $node_cacheability);

    $response_cacheability = (new CacheableMetadata())->addCacheableDependency($node_cacheability);
    if ($subject instanceof EntityInterface) {
      $response_cacheability->addCacheableDependency($subject->access('view', NULL, TRUE));
    }

    $response = new CacheableJsonResponse($node->toArray());
    $response->addCacheableDependency($response_cacheability);
    CacheabilityHeaders::apply($response, $response_cacheability);
    return $response;
  }

  /**
   * Serves the catalog of published schemas.
   */
  public function schemaCatalog(): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->schemaPublisher->catalog());
    $response->addCacheableDependency(
      (new CacheableMetadata())->addCacheTags(['component_producer_plugins', 'component_plugins']),
    );
    return $response;
  }

  /**
   * Serves one component's props schema.
   */
  public function componentSchema(string $producer): CacheableJsonResponse {
    if ($this->producerManager->getDefinition($producer, FALSE) === NULL) {
      throw new NotFoundHttpException();
    }
    $response = new CacheableJsonResponse($this->schemaPublisher->componentSchema($producer));
    $response->addCacheableDependency(
      (new CacheableMetadata())->addCacheTags(['component_producer_plugins', 'component_plugins']),
    );
    return $response;
  }

  /**
   * Serves the envelope's own schema.
   */
  public static function envelopeSchema(): CacheableJsonResponse {
    $response = new CacheableJsonResponse(SchemaPublisher::envelopeSchema());
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(3600));
    return $response;
  }

}
