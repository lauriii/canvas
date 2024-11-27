<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\TempStore\SharedTempStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * Very basic auto-save functionality.
 *
 * @todo Replace or enhance this functionality in https://drupal.org/i/3481771.
 *
 * @internal
 */
trait NotTheGoodAutoSaveTrait {

  private function doAutoSave(EntityInterface $entity, Request $request): void {
    $body = json_decode($request->getContent());
    $this->getTempStore()->set($this->getAutoSaveKey($entity), $body);
  }

  private function getTempStore(): SharedTempStore {
    return \Drupal::service('tempstore.shared')->get('experience_builder.auto_save');
  }

  protected function getAutoSaveKey(EntityInterface $entity): string {
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  protected function getAutoSaveData(EntityInterface $entity): ?object {
    return $this->getTempStore()->get($this->getAutoSaveKey($entity));
  }

}
