<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller exposing HTTP API for updating Content entities using an XB field.
 *
 * (So: "content" as in "content entity type", not as in the human-readable
 * label for the `Node` content entity type.)
 *
 * @todo Remove this controller before 0.2.0. All content entities will trigger
 *   autosaving and be published by a 'publish all' action. This is needed in
 *   the short term because the UI uses this controller to save individual nodes.
 *
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiContentUpdateForDemoController extends ApiControllerBase {

  public function __construct(
    private readonly ClientDataToEntityConverter $clientDataToEntityConverter,
  ) {}

  public function __invoke(Request $request, FieldableEntityInterface $entity): JsonResponse {
    $violations = $this->clientDataToEntityConverter->convert(json_decode($request->getContent(), TRUE), $entity);
    if ($validation_errors_response = self::createJsonResponseFromViolations($violations)) {
      return $validation_errors_response;
    }

    return self::save($entity);
  }

  /**
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected static function save(FieldableEntityInterface $entity): JsonResponse {
    if ($entity instanceof RevisionableInterface) {
      $entity->setNewRevision();
    }
    $entity->save();
    return new JsonResponse(data: ['message' => 'Saved successfully.'], status: 200);
  }

}
