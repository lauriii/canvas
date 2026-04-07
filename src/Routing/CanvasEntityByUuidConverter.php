<?php

declare(strict_types=1);

namespace Drupal\canvas\Routing;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Symfony\Component\Routing\Route;

/**
 * Provides upcasting for a UUID path parameter to the corresponding entity.
 *
 * @see canvas.routing.yml
 */
final readonly class CanvasEntityByUuidConverter implements ParamConverterInterface {

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $entity_type_id = substr($definition['type'], strlen('canvas_entity_by_uuid:'));
    $entity = $this->entityRepository->loadEntityByUuid($entity_type_id, $value);
    if ($entity === NULL) {
      throw new ParamNotConvertedException(\sprintf('The "%s" parameter was not converted because a `%s` entity with UUID %s does not exist.', $name, $entity_type_id, $value));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return !empty($definition['type']) && str_starts_with($definition['type'], 'canvas_entity_by_uuid:');
  }

}
