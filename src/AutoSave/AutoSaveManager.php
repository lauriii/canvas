<?php

namespace Drupal\experience_builder\AutoSave;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;

/**
 * Defines a class for storing and retrieving auto-save data.
 */
class AutoSaveManager {

  public const CACHE_TAG = 'experience_builder__autosave';

  public function __construct(
    private readonly AutoSaveTempStoreFactory $tempStoreFactory,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
  }

  protected function getTempStore(): AutoSaveTempStore {
    // Store for 30 days.
    $expire = 86400 * 30;
    // We need to fetch a new shared temp store from the factory for each
    // usage because the current user can change in the lifetime of a request.
    return $this->tempStoreFactory->get('experience_builder.auto_save', expire: $expire);
  }

  public function save(EntityInterface $entity, array $data): void {
    // @todo We need to combine entity-field data here and update fields the
    // user can access - https://drupal.org/i/3488368
    $key = $this->getAutoSaveKey($entity);
    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => $data,
      'data_hash' => \hash('xxh64', \serialize($data)),
      'langcode' => $entity instanceof TranslatableInterface ? $entity->language()->getId() : NULL,
      // @todo Update label from incoming entity data once it exists.
      'label' => (string) $entity->label(),
    ];
    $this->getTempStore()->set($key, $auto_save_data);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  protected function getAutoSaveKey(EntityInterface $entity): string {
    // @todo Make use of https://www.drupal.org/project/drupal/issues/3026957
    // @todo This will likely to also take into account the workspace ID.
    if ($entity instanceof TranslatableInterface) {
      return $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $entity->language()->getId();
    }
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  public function getAutoSaveData(EntityInterface $entity): ?array {
    $auto_save_data = $this->getTempStore()->get($this->getAutoSaveKey($entity));
    if (\is_null($auto_save_data)) {
      return NULL;
    }

    \assert(\is_array($auto_save_data));
    \assert(\array_key_exists('data', $auto_save_data));
    /** @var array */
    return $auto_save_data['data'];
  }

  /**
   * Gets all auto-save data.
   *
   * @return array<string, array{data: array, owner: int, updated: int, entity_type: string, entity_id: string|int, label: string, langcode: ?string}>
   *   All auto-save data entries.
   */
  public function getAllAutoSaveList(): array {
    return \array_map(static fn (object $entry) => $entry->data +
    // Append the owner and updated data into each entry.
    [
      // Remove the unique session key for anonymous users.
      'owner' => \is_numeric($entry->owner) ? (int) $entry->owner : 0,
      'updated' => $entry->updated,
    ], $this->getTempStore()->getAll());
  }

  public function delete(EntityInterface $entity): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->getTempStore()->delete($this->getAutoSaveKey($entity));
  }

}
