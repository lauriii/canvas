<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
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

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RendererInterface $renderer,
  ) {}

  public function post(): JsonResponse {
    // Note: this intentionally does not catch content entity type storage
    // handler exceptions: the generic XB API exception subscriber handles them.
    // @see \Drupal\experience_builder\EventSubscriber\ApiExceptionSubscriber
    $page = $this->entityTypeManager->getStorage('xb_page')->create([
      'title' => $this->t('Untitled page'),
      'status' => FALSE,
    ]);
    $page->save();

    return new JsonResponse([
      'entity_type' => $page->getEntityTypeId(),
      'entity_id' => $page->id(),
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
  public function list(): CacheableJsonResponse {
    // @todo introduce pagination in https://www.drupal.org/i/3502691
    $storage = $this->entityTypeManager->getStorage('xb_page');
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
      $content_list[$id] = [
        'id' => $id,
        'title' => $content_entity->label(),
        'status' => $content_entity->isPublished(),
        'path' => $generated_url->getGeneratedUrl(),
      ];
      $url_cacheability->addCacheableDependency($generated_url);
    }
    $json_response = new CacheableJsonResponse($content_list);
    // @todo add cache contexts for query params when introducing pagination in https://www.drupal.org/i/3502691.
    $json_response->addCacheableDependency($query_cacheability)
      ->addCacheableDependency($url_cacheability);
    return $json_response;
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

}
