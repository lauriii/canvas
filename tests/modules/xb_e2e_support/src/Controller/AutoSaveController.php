<?php

declare(strict_types=1);

namespace Drupal\xb_e2e_support\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\Controller\NotTheGoodAutoSaveTrait;
use Symfony\Component\HttpFoundation\JsonResponse;

class AutoSaveController {

  // @todo Remove the use of this trait or remove this entire test module in
  //   https://drupal.org/i/3489743.
  use NotTheGoodAutoSaveTrait;

  public function clearAutoSave(EntityInterface $entity): JsonResponse {
    $this->getTempStore()->delete($this->getAutoSaveKey($entity));
    return new JsonResponse(['message' => 'Auto-save cleared']);
  }

}
