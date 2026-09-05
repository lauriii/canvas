<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\ShapeMatcher\PropSourceSuggester;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Controllers exposing HTTP API for powering Content Template editor UI.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 *
 * @see \Drupal\canvas\ShapeMatcher\PropSourceSuggester
 */
final class ApiUiContentTemplateControllers extends ApiControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private readonly PropSourceSuggester $propSourceSuggester,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * Suggests prop sources for an SDC-like Component.
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
  public function suggestPropSources(string $content_entity_type_id, string $bundle, string $component_config_entity_id): JsonResponse {
    // @see \Drupal\Core\EventSubscriber\ExceptionJsonSubscriber
    $this->validateRequest($content_entity_type_id, $bundle, $component_config_entity_id);
    // @phpstan-ignore-next-line
    $source = Component::load($component_config_entity_id)->getComponentSource();
    \assert($source instanceof JsonSchemaPropsComponentSourceBase);

    $suggestions = $this->propSourceSuggester->suggest(
      $source->getSourceSpecificComponentId(),
      $source->getMetadata(),
      EntityDataDefinition::createFromDataType("entity:$content_entity_type_id:$bundle"),
    );

    return new JsonResponse(
      status: Response::HTTP_OK,
      data: PropSourceSuggester::structureSuggestionsForHierarchicalResponse($suggestions),
    );
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
    if (!$source instanceof JsonSchemaPropsComponentSourceBase) {
      throw new BadRequestHttpException('Only components that define their inputs using JSON Schema and use fields to populate their inputs are currently supported.');
    }

    if ($this->entityTypeManager->getDefinition($content_entity_type_id, FALSE) === NULL) {
      throw new NotFoundHttpException(\sprintf("The `%s` content entity type does not exist.", $content_entity_type_id));
    }

    if (!\array_key_exists($bundle, $this->entityTypeBundleInfo->getBundleInfo($content_entity_type_id))) {
      throw new NotFoundHttpException(\sprintf("The `%s` content entity type does not have a `%s` bundle.", $content_entity_type_id, $bundle));
    }
  }

  public function suggestPreviewContentEntities(string $entity_type_id, string $bundle): CacheableJsonResponse {
    $entity_query = ContentTemplate::getPreviewSuggestionQuery(
      $entity_type_id,
      $bundle,
      10,
    );

    $entity_definition = $this->entityTypeManager->getDefinition($entity_type_id);
    $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);

    $entity_ids = $entity_query->execute();
    \assert(\is_array($entity_ids));
    $entity_query_cacheability = (new CacheableMetadata())
      ->addCacheTags($entity_definition->getBundleListCacheTags($bundle))
      ->addCacheContexts($entity_storage->getEntityType()->getListCacheContexts());

    $entities = $entity_storage->loadMultiple($entity_ids);

    $entities = array_filter($entities, function ($entity) use ($entity_query_cacheability): bool {
      $access = $entity->access('view', return_as_object: TRUE);
      if ($access->isAllowed()) {
        $entity_query_cacheability->addCacheableDependency($access);
        $entity_query_cacheability->addCacheableDependency($entity);
      }
      return $access->isAllowed();
    });

    // The bundle's Field UI "Manage fields" URL, resolved generically from the
    // entity type (never a hardcoded per-entity-type route), gated on the
    // user's field-administration access. Shared across the bundle's entities.
    $manage_fields_url = self::manageFieldsUrl(
      $entity_definition->id(),
      $entity_definition->getBundleEntityType(),
      $bundle,
      $entity_query_cacheability,
    );

    $entities_data = [];
    foreach ($entities as $entity) {
      $entities_data[(string) $entity->id()] = [
        'id' => $entity->id(),
        'label' => $entity->label(),
        // Present only when the user may update this entity; gates both the
        // in-Canvas "Edit exposed slots" jump and the "Edit content" edit form.
        'editUrl' => self::entityEditFormUrl($entity, $entity_query_cacheability),
        'manageFieldsUrl' => $manage_fields_url,
      ];
    }
    $response = new CacheableJsonResponse($entities_data);
    $response->addCacheableDependency($entity_query_cacheability);
    return $response;
  }

  /**
   * The entity's edit-form URL, or NULL when it has none or is not updatable.
   */
  private static function entityEditFormUrl(EntityInterface $entity, CacheableMetadata $cacheability): ?string {
    if (!$entity->hasLinkTemplate('edit-form')) {
      return NULL;
    }
    $access = $entity->access('update', return_as_object: TRUE);
    $cacheability->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return NULL;
    }
    $generated = $entity->toUrl('edit-form')->toString(TRUE);
    $cacheability->addCacheableDependency($generated);
    return $generated->getGeneratedUrl();
  }

  /**
   * The bundle's Field UI "Manage fields" URL, or NULL when unavailable.
   *
   * Resolved from the entity type's own Field UI route (Field UI registers
   * `entity.{entity_type_id}.field_ui_fields` per fieldable entity type), so no
   * entity-type-specific route is hardcoded. NULL when Field UI is not enabled
   * for the entity type or the user lacks field-administration access.
   */
  private static function manageFieldsUrl(string $entity_type_id, ?string $bundle_entity_type, string $bundle, CacheableMetadata $cacheability): ?string {
    try {
      $url = Url::fromRoute(
        "entity.$entity_type_id.field_ui_fields",
        [$bundle_entity_type ?? 'bundle' => $bundle],
      );
      $access = $url->access(return_as_object: TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        return NULL;
      }
      $generated = $url->toString(TRUE);
      $cacheability->addCacheableDependency($generated);
      return $generated->getGeneratedUrl();
    }
    catch (RouteNotFoundException) {
      // Field UI is not enabled, or the entity type has no manage-fields
      // route. The response must re-evaluate when modules are (un)installed,
      // since enabling field_ui makes the route appear.
      $cacheability->addCacheTags(['config:core.extension']);
      return NULL;
    }
  }

  public function listViewModes(): JsonResponse {
    $data = [];

    // @todo Generalize to other content entity types in https://www.drupal.org/i/3498525
    $entity_type_id = 'node';
    $entity_view_modes = $this->entityDisplayRepository->getViewModeOptions($entity_type_id);

    foreach ($entity_view_modes as $view_mode => $view_mode_label) {
      // @see \Drupal\Core\Entity\Entity\EntityViewMode
      if ($view_mode === 'default') {
        continue;
      }

      $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);
      $template_keys = $this->entityTypeManager->getStorage(ContentTemplate::ENTITY_TYPE_ID)->getQuery()
        // TRICKY: not checking access is acceptable because the route requires
        // "create" access for ContentTemplates. This is only exposing labels
        // (the "view label" operation) and whether a ContentTemplate exists.
        ->accessCheck(FALSE)
        ->condition('content_entity_type_id', $entity_type_id)
        ->condition('content_entity_type_view_mode', $view_mode)
        ->execute();
      foreach (\array_keys($bundle_info) as $bundle) {
        $template_id = "$entity_type_id.$bundle.$view_mode";
        $data[$entity_type_id][$bundle][$view_mode] = [
          'label' => $view_mode_label,
          'hasTemplate' => \in_array($template_id, $template_keys, TRUE),
        ];
      }
    }

    return new JsonResponse(data: $data, status: Response::HTTP_OK);
  }

}
