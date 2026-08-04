<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Page;
use Drupal\canvas\Utility\HomePageHelper;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiContentAutoSaveControllers {

  /**
   * The request body field name used to pass a conflict id for resolution.
   */
  public const string RESOLVED_CONFLICT_KEY = 'resolved_conflict_id';

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly HomePageHelper $homePageHelper,
    private readonly ModuleHandlerInterface $moduleHandler,
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

    // @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3591732
    $conflict_resolution_dev_mode = $this->moduleHandler->moduleExists('canvas_dev_cd');

    // Validate that only supported fields are present in the request body.
    $allowed_fields = [$published_key, 'clientInstanceId'];
    if ($conflict_resolution_dev_mode) {
      $allowed_fields[] = self::RESOLVED_CONFLICT_KEY;
    }
    $unexpected_fields = array_diff(\array_keys($body), $allowed_fields);
    if (!empty($unexpected_fields)) {
      return new JsonResponse(
        data: ['error' => 'Unexpected fields in request body: ' . implode(', ', $unexpected_fields)],
        status: Response::HTTP_BAD_REQUEST
      );
    }

    if ($conflict_resolution_dev_mode && \array_key_exists(self::RESOLVED_CONFLICT_KEY, $body) && \array_key_exists($published_key, $body)) {
      return new JsonResponse(
        data: ['error' => \sprintf('Fields %s and %s are mutually exclusive.', $published_key, self::RESOLVED_CONFLICT_KEY)],
        status: Response::HTTP_BAD_REQUEST
      );
    }

    if ($conflict_resolution_dev_mode && \array_key_exists(self::RESOLVED_CONFLICT_KEY, $body)) {
      if (!\is_string($body[self::RESOLVED_CONFLICT_KEY]) || empty($body[self::RESOLVED_CONFLICT_KEY])) {
        return new JsonResponse(
          data: ['error' => \sprintf("Invalid format: %s", self::RESOLVED_CONFLICT_KEY)],
          status: Response::HTTP_BAD_REQUEST
        );
      }
      \assert($canvas_page instanceof Page);
      $resolved_conflict_id = $body[self::RESOLVED_CONFLICT_KEY];
      // Map each resolution outcome to a distinct response:
      // - missing auto-save item: 404
      // - auto-save item found, but has no active conflict: 422
      // - mismatching (i.e. newer) conflict: 409
      // - success: 204
      $outcome = $this->autoSaveManager->resolveConflict($canvas_page, $resolved_conflict_id);

      return $outcome->toResponse($canvas_page);
    }

    // Check if this is an unpublish operation or publish operation.
    if (!isset($body[$published_key])) {
      $required_fields = $conflict_resolution_dev_mode
        ? \sprintf('%s, %s', $published_key, self::RESOLVED_CONFLICT_KEY)
        : $published_key;
      return new JsonResponse(
        data: ['error' => \sprintf("At least one of the fields is required: %s", $required_fields)],
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
    $this->autoSaveManager->flushDeferredContentEntity($entity_to_update);

    return new JsonResponse(status: Response::HTTP_NO_CONTENT);
  }

}
