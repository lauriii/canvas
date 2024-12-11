<?php

declare(strict_types=1);

namespace Drupal\xb_e2e_support\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Symfony\Component\HttpFoundation\JsonResponse;

class AutoSaveController extends ControllerBase {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {
  }

  public function clearAutoSave(EntityInterface $entity): JsonResponse {
    $this->autoSaveManager->delete($entity);
    return new JsonResponse(['message' => 'Auto-save cleared']);
  }

}
