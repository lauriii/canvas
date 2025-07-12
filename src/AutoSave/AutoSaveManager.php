<?php

namespace Drupal\experience_builder\AutoSave;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\experience_builder\AutoSaveEntity;
use Drupal\experience_builder\Controller\ApiContentControllers;
use Drupal\experience_builder\Entity\XbHttpApiEligibleConfigEntityInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Defines a class for storing and retrieving auto-save data.
 */
class AutoSaveManager implements EventSubscriberInterface {

  public const CACHE_TAG = 'experience_builder__auto_save';
  public const string PUBLISH_PERMISSION = 'publish auto-saves';

  const ENTITY_DUPLICATE_SUFFIX = ' (Copy)';

  public function __construct(
    private readonly AutoSaveTempStoreFactory $tempStoreFactory,
    private readonly ConfigManagerInterface $configManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'cache.static')]
    private readonly CacheBackendInterface $cache,
  ) {
  }

  protected function getTempStore(): AutoSaveTempStore {
    // Store for 30 days.
    $expire = 86400 * 30;
    // We need to fetch a new shared temp store from the factory for each
    // usage because the current user can change in the lifetime of a request.
    return $this->tempStoreFactory->get('experience_builder.auto_save', expire: $expire);
  }

  /**
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  protected function getFormViolationTempStore(): AutoSaveTempStore {
    // Store for 30 days.
    $expire = 86400 * 30;
    // We need to fetch a new shared temp store from the factory for each
    // usage because the current user can change in the lifetime of a request.
    return $this->tempStoreFactory->get('experience_builder.auto_save.form_violations', expire: $expire);
  }

  public function saveEntity(EntityInterface $entity, ?string $clientId = NULL): void {
    $key = $this->getAutoSaveKey($entity);
    $data = self::normalizeEntity($entity);
    $data_hash = self::generateHash($data);
    $original_hash = $this->getUnchangedHash($entity);
    $has_form_violations = FALSE;
    if ($entity instanceof FieldableEntityInterface) {
      $has_form_violations = $this->getEntityFormViolations($entity)->count() > 0;
    }
    // 💡 If you are debugging why an entry is being created, but you didn't
    // expect one to be, the code below can be evaluated in a debugger and will
    // show you which field varies.
    // @code
    // $original = self::normalizeEntity($this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id()))
    // $data_hash = \array_map(self::generateHash(...), $data)
    // $original_hash = \array_map(self::generateHash(...), $original)
    // \array_diff($data_hash, $original_hash)
    // \array_diff($original_hash, $data_hash)
    // @endcode
    if ($original_hash !== NULL && \hash_equals($original_hash, $data_hash) && !$has_form_violations) {
      // We've reset back to the original values. Clear the auto-save entry but
      // keep the hash.
      $this->delete($entity);
      return;
    }

    $auto_save_data = [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
      'data' => $entity->toArray(),
      'langcode' => $entity->language()->getId(),
      'label' => $entity->label(),
      'data_hash' => $data_hash,
      'client_id' => $clientId,
    ];
    $this->getTempStore()->set($key, $auto_save_data);
    $this->cache->delete($key);
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

  /**
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  public function saveEntityFormViolations(FieldableEntityInterface $entity, ?ConstraintViolationListInterface $violations = NULL): self {
    $key = self::getAutoSaveKey($entity);
    if ($violations === NULL) {
      $this->getFormViolationTempStore()->delete($key);
      return $this;
    }
    $this->getFormViolationTempStore()->set($key, $violations);
    $this->cache->delete($key);
    return $this;
  }

  /**
   * @todo Remove this in https://drupal.org/i/3505018.
   */
  public function getEntityFormViolations(FieldableEntityInterface $entity): ConstraintViolationListInterface {
    return $this->getFormViolationTempStore()->get(self::getAutoSaveKey($entity)) ?? new ConstraintViolationList();
  }

  private static function normalizeEntity(EntityInterface $entity): array {
    if (!$entity instanceof FieldableEntityInterface) {
      return $entity->toArray();
    }
    $normalized = [];
    $fields = $entity->getFields();
    if ($entity instanceof EntityChangedInterface) {
      // If the entity has a 'changed' field, we don't want to include it in the
      // normalized data, as will be updated when we create an entity to
      // compare against the save version.
      // @see \Drupal\experience_builder\AutoSave\AutoSaveManager::getAutoSaveEntity().
      $fields = \array_filter($fields, static fn (FieldItemListInterface $field) => $field->getFieldDefinition()->getType() !== 'changed');
      // Similarly, we don't want to include the 'externalUpdates' field as it's
      // not truly an entity field, but something used to track programmatic
      // updates to the entity data in Redux.
      // @todo We can probably remove this when we refactor the pageData slice
      //   in https://drupal.org/i/3535569.
      $fields = \array_filter($fields, static fn (FieldItemListInterface $field) => $field->getFieldDefinition()->getType() !== 'externalUpdates');

    }
    foreach (\array_keys($fields) as $name) {
      $items = $entity->get($name);
      // Exclude items that are empty.
      if ($items->isEmpty()) {
        continue;
      }
      $normalized[$name] = \array_map(
        static function (FieldItemInterface $item): array {
          if ($item instanceof ComponentTreeItem) {
            // Optimize component inputs to ensure the normalized value is
            // determinative.
            $item->optimizeInputs();
          }
          $value = $item->toArray();
          foreach (\array_filter($item->getProperties(), static fn (TypedDataInterface $property) => $property instanceof PrimitiveInterface) as $property) {
            \assert($property instanceof PrimitiveInterface);
            // For items that support it, cast to their primitive value, this
            // ensures consistency, for example a boolean field with value '1'
            // will be normalized to TRUE.
            $value[$property->getName()] = $property->getCastedValue();
          }
          return $value;
        },
        \iterator_to_array($items)
      );
    }
    return $normalized;
  }

  public static function getAutoSaveKey(EntityInterface $entity): string {
    // @todo Make use of https://www.drupal.org/project/drupal/issues/3026957
    // @todo This will likely to also take into account the workspace ID.
    if ($entity instanceof TranslatableInterface) {
      return $entity->getEntityTypeId() . ':' . $entity->id() . ':' . $entity->language()->getId();
    }
    return $entity->getEntityTypeId() . ':' . $entity->id();
  }

  private function getUnchangedHash(EntityInterface $entity): ?string {
    assert(!is_null($entity->id()));
    $original = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($original === NULL) {
      return NULL;
    }
    return self::generateHash(self::normalizeEntity($original));
  }

  public function getAutoSaveEntity(EntityInterface $entity): AutoSaveEntity {
    $key = $this->getAutoSaveKey($entity);
    $cached = $this->cache->get($key);
    if ($cached) {
      \assert($cached->data instanceof AutoSaveEntity);
      return $cached->data;
    }
    $auto_save_data = $this->getTempStore()->get($key);
    if (\is_null($auto_save_data)) {
      return AutoSaveEntity::empty();
    }

    \assert(\is_array($auto_save_data));
    \assert(\array_key_exists('data', $auto_save_data));
    \assert(\array_key_exists('entity_type', $auto_save_data));
    \assert(\is_array($auto_save_data['data']));
    // Create an entity from the stored values, but don't call ::enforceIsNew on
    // it to avoid possible issues where someone accidentally calls ::save on
    // the entity. Calling code that needs to reflect the fact that the entity
    // is not new should call ::enforceIsNew as required.
    $auto_save_entity = new AutoSaveEntity($this->entityTypeManager->getStorage($auto_save_data['entity_type'])->create($auto_save_data['data']), $auto_save_data['data_hash'], $auto_save_data['client_id']);
    // Store in static cache to avoid the overhead of calling Entity::create
    // multiple times during layout preview rendering.
    $this->cache->set($key, $auto_save_entity, tags: [self::CACHE_TAG]);
    return $auto_save_entity;
  }

  /**
   * Gets all auto-save data.
   *
   * @return array<string, array{data: array, owner: int, updated: int, entity_type: string, entity_id: string|int, label: string, langcode: ?string, entity: ?EntityInterface}>
   *   All auto-save data entries.
   */
  public function getAllAutoSaveList(bool $with_entities = FALSE): array {
    return \array_map(fn (object $entry) => $entry->data +
    // Append the owner and updated data into each entry, and an entity object
    // upon request.
    [
      // Remove the unique session key for anonymous users.
      'owner' => \is_numeric($entry->owner) ? (int) $entry->owner : 0,
      'updated' => $entry->updated,
      'entity' => $with_entities ? $this->entityTypeManager->getStorage($entry->data['entity_type'])->create($entry->data['data']) : NULL,
    ], $this->getTempStore()->getAll());
  }

  /**
   * @see ::onXbConfigEntitySave()
   */
  public function delete(EntityInterface $entity): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $key = $this->getAutoSaveKey($entity);
    $this->getTempStore()->delete($key);
    $this->getFormViolationTempStore()->delete($key);
  }

  public function deleteAll(): void {
    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
    $this->getTempStore()->deleteAll();
    $this->getFormViolationTempStore()->deleteAll();
  }

  private static function generateHash(array $data): string {
    // When called from ::recordInitialClientSideRepresentation() and ::save()
    // the keys for an individual component are in different orders. This causes
    // the hash to be different though the data is functionally the same.
    self::recursiveKsort($data);
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

  public function onXbConfigEntitySave(ConfigCrudEvent $event): void {
    [$module] = explode('.', $event->getConfig()->getName(), 2);
    if ($module !== 'experience_builder') {
      return;
    }

    $entity = $this->configManager->loadConfigEntityByName($event->getConfig()->getName());
    if (!$entity) {
      return;
    }
    // Auto-saves can only occur for XB config entities modified by the XB UI.
    if (!$entity instanceof XbHttpApiEligibleConfigEntityInterface) {
      return;
    }

    $autoSaveData = $this->getAutoSaveEntity($entity);
    if ($autoSaveData->isEmpty()) {
      return;
    }
    $autoSaveEntity = $autoSaveData->entity;
    assert($autoSaveEntity instanceof XbHttpApiEligibleConfigEntityInterface);

    // Update the `label` and `status` keys of the config entity, if they've
    // changed.
    // @todo Consider auto-updating the auto-save entries for other config entity properties, but that will need very careful evaluation.
    $auto_save_update_needed = FALSE;
    assert($entity->getEntityType() instanceof ConfigEntityTypeInterface);
    $properties_to_assess = $entity->getEntityType()->getPropertiesToExport();
    assert(is_array($properties_to_assess));
    $auto_save_updatable_properties = \array_intersect_key($entity->getEntityType()->getKeys(), \array_flip(['status', 'label']));

    // Ensure that no properties other than `status` and `label` were modified;
    // otherwise the auto-save entry must be deleted.
    $auto_save_not_updatable_properties = \array_diff_key($properties_to_assess, array_flip($auto_save_updatable_properties));
    foreach ($auto_save_not_updatable_properties as $property) {
      if ($event->isChanged($property)) {
        $this->delete($entity);
        return;
      }
    }

    foreach ($auto_save_updatable_properties as $auto_save_updatable_property) {
      if ($event->isChanged($auto_save_updatable_property)) {
        $autoSaveEntity->set($auto_save_updatable_property, $entity->get($auto_save_updatable_property));
        $auto_save_update_needed = TRUE;
      }
    }

    // Finally: the goal: to update rather than delete the auto-save entry when
    // safe.
    if ($auto_save_update_needed) {
      $this->saveEntity($autoSaveEntity, $autoSaveData->clientId);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[ConfigEvents::SAVE][] = ['onXbConfigEntitySave'];
    return $events;
  }

  public static function contentEntityIsConsideredNew(ContentEntityInterface $entity): bool {
    return (string) $entity->label() == ApiContentControllers::defaultTitle($entity->getEntityType()) || str_ends_with((string) $entity->label(), self::ENTITY_DUPLICATE_SUFFIX);
  }

}
