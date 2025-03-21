<?php

namespace Drupal\experience_builder\AutoSave;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\experience_builder\AutoSaveData;

/**
 * Defines a class for storing and retrieving auto-save data.
 */
class AutoSaveManager {

  public const CACHE_TAG = 'experience_builder__auto_save';

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

  protected function getHashStore(): AutoSaveTempStore {
    // Store for 30 days.
    $expire = 86400 * 30;
    // We need to fetch a new shared temp store from the factory for each
    // usage because the current user can change in the lifetime of a request.
    return $this->tempStoreFactory->get('experience_builder.auto_save.hashes', expire: $expire);
  }

  public function save(EntityInterface $entity, array $data): void {
    $key = $this->getAutoSaveKey($entity);
    $data_hash = self::generateHash($data);
    $stored_hash = $this->readHash($entity);
    if ($stored_hash !== NULL && \hash_equals($stored_hash, $data_hash)) {
      // We've reset back to the original values. Clear the auto-save entry but
      // keep the hash.
      $this->delete($entity, FALSE);
      return;
    }

    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => $data,
      'data_hash' => $data_hash,
      'langcode' => $entity instanceof TranslatableInterface ? $entity->language()->getId() : NULL,
      'label' => self::getLabelToSave($entity, $data),
    ];
    $this->getTempStore()->set($key, $auto_save_data);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  public static function getLabelToSave(EntityInterface $entity, array $data): string {
    $key = \sprintf("%s[0][value]", $entity->getEntityType()->getKey('label'));
    return empty($data['entity_form_fields'][$key]) ?
      (string) $entity->label() :
      (string) $data['entity_form_fields'][$key];
  }

  public static function getAutoSaveKey(EntityInterface $entity): string {
    // @todo Make use of https://www.drupal.org/project/drupal/issues/3026957
    // @todo This will likely to also take into account the workspace ID.
    if ($entity instanceof TranslatableInterface) {
      return $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $entity->language()->getId();
    }
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  public function recordInitialClientSideRepresentation(EntityInterface $entity, array $data): static {
    $this->getHashStore()->set(self::getAutoSaveKey($entity), self::generateHash($data));
    return $this;
  }

  private function readHash(EntityInterface $entity): ?string {
    return $this->getHashStore()->get(self::getAutoSaveKey($entity));
  }

  public function getAutoSaveData(EntityInterface $entity): AutoSaveData {
    $auto_save_data = $this->getTempStore()->get($this->getAutoSaveKey($entity));
    if (\is_null($auto_save_data)) {
      return new AutoSaveData(NULL);
    }

    \assert(\is_array($auto_save_data));
    \assert(\array_key_exists('data', $auto_save_data));
    \assert(\is_array($auto_save_data['data']));
    return new AutoSaveData($auto_save_data['data']);
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

  /**
   * @see \experience_builder_entity_update()
   */
  public function delete(EntityInterface $entity, bool $resetHash = TRUE): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->getTempStore()->delete($this->getAutoSaveKey($entity));
    if ($resetHash) {
      $this->getHashStore()->delete($this->getAutoSaveKey($entity));
    }
  }

  public function deleteAll(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->getTempStore()->deleteAll();
    $this->getHashStore()->deleteAll();
  }

  private static function generateHash(array $data): string {
    // When called from ::recordInitialClientSideRepresentation() and ::save()
    // the keys for an individual component are in different orders. This causes
    // the hash to be different though the data is functionally the same.
    self::recursiveKsort($data);
    // @todo Determine if model entries that contain no information besides
    //   'name' should be removed from the model for hashing purposes in
    //   https://drupal.org/i/3511447.
    // Remove values that only exist for retrieving form state cache during
    // entity conversion and should not influence the hash.
    unset(
      $data['entity_form_fields']['form_build_id'],
      $data['entity_form_fields']['form_id'],
      $data['entity_form_fields']['form_token'],
    );
    // We use \json_encode here instead of \serialize because we're not dealing
    // with PHP Objects and this ensures the representation hashed from PHP is
    // consistent with the representation transmitted by the client. Some of the
    // UTF characters we use in expressions are represented differently in JSON
    // encoding and hence using \serialize would yield two different hashes
    // depending on whether the hashing occurred before/after transfer from the
    // client.
    return \hash('xxh64', \json_encode($data, JSON_THROW_ON_ERROR));
  }

  private static function recursiveKsort(array &$array): void {
    ksort($array);
    foreach ($array as &$value) {
      if (is_array($value)) {
        self::recursiveKsort($value);
      }
    }
  }

}
