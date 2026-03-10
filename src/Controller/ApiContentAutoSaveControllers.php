<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Utility\HomePageHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiContentAutoSaveControllers {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly HomePageHelper $homePageHelper,
  ) {}

  /**
   * Unpublishes or publishes entity through auto-save.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $canvas_page
   *   Entity to unpublish or publish.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Response.
   */
  public function patch(ContentEntityInterface $canvas_page, Request $request): JsonResponse {
    $content = $request->getContent();
    $body = json_decode($content, TRUE);

    \assert($canvas_page instanceof EntityPublishedInterface);
    $entity_type = $canvas_page->getEntityType();
    $published_key = $entity_type->getKey('published');
    \assert(\is_string($published_key), 'Entity type must have a `published` key');

    // Validate that only supported fields are present in the request body.
    $allowed_fields = [$published_key, 'clientInstanceId'];
    $unexpected_fields = array_diff(\array_keys($body), $allowed_fields);
    if (!empty($unexpected_fields)) {
      return new JsonResponse(
        data: ['error' => 'Unexpected fields in request body: ' . implode(', ', $unexpected_fields)],
        status: Response::HTTP_BAD_REQUEST
      );
    }

    // Check if this is an unpublish operation or publish operation.
    if (!isset($body[$published_key])) {
      return new JsonResponse(
        data: ['error' => "Missing required field: {$published_key}"],
        status: Response::HTTP_BAD_REQUEST
      );
    }

    // Get the auto-saved version if available, otherwise use the original
    // entity.
    $autoSaveData = $this->autoSaveManager->getAutoSaveEntity($canvas_page);
    $entity_to_update = $autoSaveData->isEmpty()
      ? $canvas_page
      : $autoSaveData->entity;
    \assert($entity_to_update instanceof EntityPublishedInterface);

    // Set the entity status based on the request.
    \assert(\is_bool($body[$published_key]));
    if ($body[$published_key] === FALSE) {
      // Prevent unpublishing the homepage.
      if ($canvas_page->isPublished() && $this->homePageHelper->isHomepage($canvas_page)) {
        return new JsonResponse(
          data: ['error' => 'Cannot unpublish the homepage. Please set a different page as the homepage first.'],
          status: Response::HTTP_FORBIDDEN
        );
      }
      $entity_to_update->setUnpublished();
    }
    else {
      $entity_to_update->setPublished();
    }

    // Save through auto-save instead of directly saving.
    $clientInstanceId = $body['clientInstanceId'] ?? NULL;
    $this->autoSaveManager->saveEntity($entity_to_update, $clientInstanceId);

    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

}
