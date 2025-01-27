<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates new xb_page entity.
 *
 * New entity must be unpublished and have a hardcoded initial title.
 *
 * @internal This HTTP API is intended only for the XB UI. These controllers
 *   and associated routes may change at any time.
 *
 * @todo https://www.drupal.org/i/3498525 should generalize this to all eligible content entity types
 */
final class ApiContentCreateXbPage {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function __invoke(): JsonResponse {
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

}
