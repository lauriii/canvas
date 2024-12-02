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

  private const AUTO_SAVE_LIST_KEY = 'AUTO_SAVE_LIST_KEY';

  private function getAllKeys(): array {
    $all_keys = $this->getTempStore()->get(self::AUTO_SAVE_LIST_KEY) ?? [];
    assert(is_array($all_keys));
    return $all_keys;
  }

  private function doAutoSave(EntityInterface $entity, Request $request): void {
    $body = json_decode($request->getContent());
    $key = $this->getAutoSaveKey($entity);
    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => $body,
    ];
    $this->getTempStore()->set($key, $auto_save_data);
    $all_keys = $this->getAllKeys();
    if (!in_array($key, $all_keys)) {
      $all_keys[] = $key;
      $this->getTempStore()->set(self::AUTO_SAVE_LIST_KEY, $all_keys);
    }
  }

  public function getAllAutoSaveList(): array {
    $all_auto_saves = [];
    foreach ($this->getAllKeys() as $key) {
      $data = $this->getTempStore()->get($key);
      if ($data) {
        $all_auto_saves[$key] = $data;
      }
    }
    return $all_auto_saves;
  }

  private function getTempStore(): SharedTempStore {
    return \Drupal::service('tempstore.shared')->get('experience_builder.auto_save');
  }

  protected function getAutoSaveKey(EntityInterface $entity): string {
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  protected function getAutoSaveData(EntityInterface $entity): ?object {
    $auto_save_data = $this->getTempStore()->get($this->getAutoSaveKey($entity));
    if (is_null($auto_save_data)) {
      return NULL;
    }
    else {
      assert(is_array($auto_save_data));
      assert(is_object($auto_save_data['data']));
      return $auto_save_data['data'];
    }
  }

  protected function removeAutoSave(EntityInterface $entity): void {
    $key = $this->getAutoSaveKey($entity);
    $all_keys = array_diff($this->getAllKeys(), [$key]);
    $this->getTempStore()->set(self::AUTO_SAVE_LIST_KEY, $all_keys);
    $this->getTempStore()->delete($key);
  }

}
