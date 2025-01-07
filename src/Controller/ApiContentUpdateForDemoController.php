<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\ClientDataToEntityConverter;
use Symfony\Component\HttpFoundation\JsonResponse;

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
    private readonly AutoSaveManager $autoSaveManager,
  ) {}

  public function __invoke(FieldableEntityInterface $entity): JsonResponse {
    $auto_save = $this->autoSaveManager->getAutoSaveData($entity);
    assert(is_array($auto_save));
    $this->clientDataToEntityConverter->convert([
      'layout' => reset($auto_save['layout']),
      'model' => $auto_save['model'],
      'entity_form_fields' => $auto_save['entity_form_fields'],
    ], $entity);

    return self::save($entity);
  }

  /**
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function save(FieldableEntityInterface $entity): JsonResponse {
    if ($entity instanceof RevisionableInterface) {
      $entity->setNewRevision();
    }
    $entity->save();
    $this->autoSaveManager->delete($entity);
    return new JsonResponse(data: ['message' => 'Saved successfully.'], status: 200);
  }

}
