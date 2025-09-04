<?php

namespace Drupal\canvas\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;
use Drupal\canvas\PropSource\DynamicPropSource;
use Drupal\canvas\ShapeMatcher\FieldForComponentSuggester;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controllers exposing HTTP API for powering Canvas's Content Template editor UI.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 *
 * @see \Drupal\canvas\ShapeMatcher\FieldForComponentSuggester
 */
final class ApiUiContentTemplateControllers extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly FieldForComponentSuggester $fieldForComponentSuggester,
  ) {}

  /**
   * Provides suggestions for a given Component based on entity type and bundle.
   *
   * @param string $content_entity_type_id
   *   A content entity type ID.
   * @param string $bundle
   *   A bundle of the given content entity type.
   * @param string $component_config_entity_id
   *   A Component config entity ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the suggestions for the component.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function suggestStructuredDataForPropShapes(string $content_entity_type_id, string $bundle, string $component_config_entity_id): JsonResponse {
    // @see \Drupal\Core\EventSubscriber\ExceptionJsonSubscriber
    $this->validateRequest($content_entity_type_id, $bundle, $component_config_entity_id);
    // @phpstan-ignore-next-line
    $source = Component::load($component_config_entity_id)->getComponentSource();
    assert($source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);

    $suggestions = $this->fieldForComponentSuggester->suggest(
      $source->getSdcPlugin()->getPluginId(),
      EntityDataDefinition::createFromDataType("entity:$content_entity_type_id:$bundle"),
    );

    return new JsonResponse(status: Response::HTTP_OK, data: array_combine(
      // Top-level keys: the prop names of the targeted component.
      array_map(
        fn (string $key): string => ComponentPropExpression::fromString($key)->propName,
        array_keys($suggestions),
      ),
      array_map(
        fn (array $instances): array => array_combine(
          // Second level keys: opaque identifiers for the suggestions to
          // populate the component prop.
          array_map(
            fn (StructuredDataPropExpressionInterface $expr): string => \hash('xxh64', (string) $expr),
            array_values($instances),
          ),
          // Values: objects with "label" and "source" keys, with:
          // - "label": the human-readable label that the Content Template UI
          //   should present to the human
          // - "source": the array representation of the DynamicPropSource that,
          //   if selected by the human, the client should use verbatim as the
          //   source to populate this component instance's prop.
          array_map(
            function (string $label, StructuredDataPropExpressionInterface $expr) {
              return [
                'label' => $label,
                'source' => (new DynamicPropSource($expr))->toArray(),
              ];
            },
            array_keys($instances),
            array_values($instances),
          ),
        ),
        array_column($suggestions, 'instances'),
      ),
    ));
  }

  /**
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  private function validateRequest(string $content_entity_type_id, string $bundle, string $component_config_entity_id): void {
    $component = Component::load($component_config_entity_id);
    if (NULL === $component) {
      throw new NotFoundHttpException("The component $component_config_entity_id does not exist.");
    }

    $source = $component->getComponentSource();
    if (!$source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
      throw new BadRequestHttpException('Only components that define their inputs using JSON Schema and use fields to populate their inputs are currently supported.');
    }

    // @todo Add support for suggestions for code components in https://www.drupal.org/i/3503038
    if (!$source instanceof SingleDirectoryComponent) {
      throw new BadRequestHttpException('Code components are not supported yet.');
    }

    if ($this->entityTypeManager->getDefinition($content_entity_type_id, FALSE) === NULL) {
      throw new NotFoundHttpException(sprintf("The `%s` content entity type does not exist.", $content_entity_type_id));
    }

    if (!array_key_exists($bundle, $this->entityTypeBundleInfo->getBundleInfo($content_entity_type_id))) {
      throw new NotFoundHttpException(sprintf("The `%s` content entity type does not have a `%s` bundle.", $content_entity_type_id, $bundle));
    }
  }

  public function suggestPreviewContentEntities(string $entity_type_id, string $bundle): CacheableJsonResponse {
    $entity_definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);

    $metadata = new CacheableMetadata();

    $id_key = $entity_definition->getKey('id');
    assert(is_string($id_key));
    $entity_query = $entity_storage->getQuery()
      ->accessCheck(TRUE)
      ->range(0, 10);
    if ($entity_definition->hasKey('bundle')) {
      $bundle_key = $entity_definition->getKey('bundle');
      assert(is_string($bundle_key));
      $entity_query->condition($bundle_key, $bundle);
    }
    // @todo Remove conditionality in https://www.drupal.org/i/3498525
    if ($entity_definition->hasKey('published')) {
      $published_key = $entity_definition->getKey('published');
      assert(is_string($published_key));
      $entity_query->condition($published_key, TRUE);
    }
    // @todo Remove conditionality in https://www.drupal.org/i/3498525
    if ($entity_definition->entityClassImplements(EntityChangedInterface::class)) {
      $entity_query->sort('changed', 'DESC');
    }
    else {
      $entity_query->sort($id_key, 'DESC');
    }

    $entity_ids = $entity_query->execute();
    $entities = $entity_storage->loadMultiple($entity_ids);

    $entities = array_filter($entities, function ($entity) use ($metadata): bool {
      $access = $entity->access('view', return_as_object: TRUE);
      if ($access->isAllowed()) {
        $metadata->addCacheableDependency($access);
        $metadata->addCacheableDependency($entity);
      }
      return $access->isAllowed();
    });
    $metadata->addCacheTags($entity_definition->getBundleListCacheTags($bundle));

    $entities_data = array_map(fn (EntityInterface $entity) => [
      'id' => $entity->id(),
      'label' => $entity->label(),
    ], $entities);
    $response = new CacheableJsonResponse($entities_data);
    $response->addCacheableDependency($metadata);
    return $response;
  }

}
