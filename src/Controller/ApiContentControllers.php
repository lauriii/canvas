<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Resource\XbResourceLink;
use Drupal\experience_builder\Resource\XbResourceLinkCollection;
use Drupal\experience_builder\XbUriDefinitions;
use http\Exception\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP API for interacting with XB-eligible Content entity types.
 *
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 *
 * @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types
 */
final class ApiContentControllers {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
    private readonly AutoSaveManager $autoSaveManager,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  public function post(Request $request, string $entity_type): JsonResponse {
    // Get the request body content
    $content = $request->getContent();
    $body = json_decode($content, TRUE);
    $entity = NULL;

    // Try to load the entity instance.
    if (isset($body['entity_id'])) {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($body['entity_id']);
      if (!$entity instanceof ContentEntityInterface || !$entity->access('view')) {
        return new JsonResponse(['error' => 'Cannot find entity to duplicate.'], Response::HTTP_NOT_FOUND);
      }
    }

    // If entity is provided, duplicate it, otherwise create a new entity.
    if ($entity) {
      $new = $this->duplicate($entity);
    }
    else {
      // Note: this intentionally does not catch content entity type storage
      // handler exceptions: the generic XB API exception subscriber handles them.
      // @see \Drupal\experience_builder\EventSubscriber\ApiExceptionSubscriber
      $entity_type_definition = $this->entityTypeManager->getDefinition($entity_type);
      $new = $this->entityTypeManager->getStorage($entity_type)->create([
        'title' => static::defaultTitle($entity_type_definition),
        'status' => FALSE,
      ]);
      $new->save();
    }

    return new JsonResponse([
      'entity_type' => $entity_type,
      'entity_id' => $new->id(),
    ], RESPONSE::HTTP_CREATED);
  }

  /**
   * Deletes entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $xb_page
   *   Entity to delete.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Response.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function delete(ContentEntityInterface $xb_page): JsonResponse {
    $xb_page->delete();
    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

  /**
   * Returns a list of XB Page content entities, with only high-level metadata.
   *
   * TRICKY: there are reasons XB has its own internal HTTP API rather than
   * using Drupal core's JSON:API. As soon as this method is updated to return
   * all fields instead of just high-level metadata, those reasons may start to
   * outweigh the downsides of adding a dependency on JSON:API.
   *
   * @see https://www.drupal.org/project/experience_builder/issues/3500052#comment-15966496
   */
  public function list(string $entity_type): CacheableJsonResponse {
    // @todo introduce pagination in https://www.drupal.org/i/3502691
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query_cacheability = (new CacheableMetadata())
      ->addCacheContexts($storage->getEntityType()->getListCacheContexts())
      ->addCacheTags($storage->getEntityType()->getListCacheTags());
    $url_cacheability = new CacheableMetadata();
    // We don't need to worry about the status of the page, as we need both
    // published and unpublished pages on the frontend.
    $entity_query = $storage->getQuery()->accessCheck(TRUE);
    $ids = $this->executeQueryInRenderContext($entity_query, $query_cacheability);
    /** @var \Drupal\Core\Entity\EntityPublishedInterface[] $content_entities */
    $content_entities = $storage->loadMultiple($ids);
    $content_list = [];

    foreach ($content_entities as $content_entity) {
      $id = (int) $content_entity->id();
      $generated_url = $content_entity->toUrl()->toString(TRUE);

      $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($content_entity);
      // Expose available entity operations.
      $linkCollection = $this->getEntityOperations($content_entity);
      $autoSaveEntity = $autoSaveData->isEmpty() ? NULL : $autoSaveData->entity;

      // @todo Dynamically use the entity 'path' key to determine which field is
      //   the path in https://drupal.org/i/3503446.
      $autoSavePath = NULL;
      if ($autoSaveEntity instanceof FieldableEntityInterface && $autoSaveEntity->hasField('path')) {
        $autoSavePath = $autoSaveEntity->get('path')->first()?->getValue()['alias'] ?? \sprintf('/%s', \ltrim($autoSaveEntity->toUrl()->getInternalPath(), '/'));
      }

      $content_list[$id] = [
        'id' => $id,
        'title' => $content_entity->label(),
        'status' => $content_entity->isPublished(),
        'path' => $generated_url->getGeneratedUrl(),
        'autoSaveLabel' => $autoSaveEntity?->label(),
        'autoSavePath' => $autoSavePath,
        // @see https://jsonapi.org/format/#document-links
        'links' => $linkCollection->asArray(),
      ];
      $url_cacheability->addCacheableDependency($generated_url)
        ->addCacheableDependency($linkCollection);
    }
    $json_response = new CacheableJsonResponse($content_list);
    // @todo add cache contexts for query params when introducing pagination in https://www.drupal.org/i/3502691.
    $json_response->addCacheableDependency($query_cacheability)
      ->addCacheableDependency($url_cacheability);
    if (isset($autoSaveData)) {
      $json_response->addCacheableDependency($autoSaveData);
    }
    return $json_response;
  }

  /**
   * Duplicates entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to duplicate.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   Newly created entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function duplicate(ContentEntityInterface $entity): ContentEntityInterface {
    $duplicate = $entity->createDuplicate();

    // Get temp data of original entity.
    if ($entity = $this->autoSaveManager->getAutoSaveEntity($entity)->entity) {
      // Before merging temp data remove path value to avoid collision.
      // @todo Remove hardcoded field name when https://www.drupal.org/project/experience_builder/issues/3503446 lands.
      $duplicate = $entity->createDuplicate();
      \assert($duplicate instanceof ContentEntityInterface);
    }

    // Update title and status.
    $entity_type = $duplicate->getEntityType();
    $entity_key = $entity_type->getKey('label') ?? 'title';
    // @phpstan-ignore-next-line
    $duplicate->set($entity_key, $duplicate->label() . AutoSaveManager::ENTITY_DUPLICATE_SUFFIX);
    assert($duplicate instanceof EntityPublishedInterface);
    $duplicate->setUnpublished();
    $duplicate->save();

    // Delete temp data for the duplicate, it should not have it at this point.
    // Everything is saved.
    $this->autoSaveManager->delete($duplicate);

    return $duplicate;
  }

  /**
   * Executes the query in a render context, to catch bubbled cacheability.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query to execute to get the return results.
   * @param \Drupal\Core\Cache\CacheableMetadata $query_cacheability
   *   The value object to carry the query cacheability.
   *
   * @return array
   *   Returns IDs of entities.
   *
   * @see \Drupal\jsonapi\Controller\EntityResource::executeQueryInRenderContext()
   */
  private function executeQueryInRenderContext(QueryInterface $query, CacheableMetadata $query_cacheability) : array {
    $context = new RenderContext();
    $results = $this->renderer->executeInRenderContext($context, function () use ($query) {
      return $query->execute();
    });
    if (!$context->isEmpty()) {
      $query_cacheability->addCacheableDependency($context->pop());
    }
    return $results;
  }

  public static function defaultTitle(EntityTypeInterface $entity_type): TranslatableMarkup {
    return new TranslatableMarkup('Untitled @singular_entity_type_label', ['@singular_entity_type_label' => $entity_type->getSingularLabel()]);
  }

  public function getEntityOperations(EntityPublishedInterface $content_entity): XbResourceLinkCollection {
    $links = new XbResourceLinkCollection([]);
    // Link relation type => route name.
    $possible_operations = [
      XbUriDefinitions::LINK_REL_DELETE => ['route_name' => 'experience_builder.api.content.delete', 'op' => 'delete'],
      XbUriDefinitions::LINK_REL_EDIT => ['route_name' => 'experience_builder.experience_builder', 'op' => 'update'],
      // @todo Fix when https://www.drupal.org/i/3503412 lands.
      // XbUriDefinitions::LINK_REL_SET_AS_HOMEPAGE => ['route_name' => 'experience_builder.api.homepage.post', 'op' => 'update'],
      XbUriDefinitions::LINK_REL_DUPLICATE => ['route_name' => 'experience_builder.api.content.create', 'op' => 'update'],
    ];
    foreach ($possible_operations as $link_rel => ['route_name' => $route_name, 'op' => $entity_operation]) {
      $access = $content_entity->access(operation: $entity_operation, return_as_object: TRUE);
      assert($access instanceof AccessResult);
      if ($access->isAllowed()) {
        $links = $links->withLink(
          $link_rel,
          new XbResourceLink($access, $this->getUrlFromRoute($content_entity, $route_name), $link_rel)
        );
      }
      else {
        $links->addCacheableDependency($access);
      }
    }
    return $links;
  }

  /**
   * Gets the url for an operation route given the content entity.
   *
   * Ideally, we would have standardized routes, and we wouldn't need a helper,
   * nor to compile the routes.
   * This might be achievable when we complete https://www.drupal.org/i/3498525.
   */
  private function getUrlFromRoute(EntityInterface $content_entity, string $route_name): Url {
    // @todo https://www.drupal.org/i/3498525 should standardize the
    //   route params. We need this helper for now.
    $match = fn($param) => match($param) {
      'entity_type' => $content_entity->getEntityTypeId(),
      'entity' => $content_entity->id(),
      $content_entity->getEntityTypeId() => $content_entity->id(),
      default => throw new InvalidArgumentException('We cannot map this route parameter'),
    };
    $route = $this->routeProvider->getRouteByName($route_name);
    $route_parameters = $route->compile()->getVariables();
    $params = [];
    foreach ($route_parameters as $param) {
      $params[$param] = $match($param);
    }
    return Url::fromRoute($route_name, $params);
  }

}
