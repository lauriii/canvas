<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Shared storage logic for config entities that live entirely in auto-save.
 *
 * Concrete storage classes must:
 * 1. Implement `createStub(string $id): EntityInterface` to produce the
 *    minimal entity object needed by AutoSaveManager::getAutoSaveEntity().
 * 2. Implement `publish(EntityInterface $entity): void` with the entity-type-
 *    specific logic that applies the staged change to live storage.
 *
 * @see \Drupal\canvas\EntityHandlers\StagedConfigUpdateStorage
 * @see \Drupal\canvas\EntityHandlers\StagedLanguageConfigOverrideStorage
 */
trait StagedConfigEntityStorageTrait {

  private AutoSaveManager $autoSaveManager;

  abstract protected function createStub(string $id): EntityInterface;

  abstract protected function publish(EntityInterface $entity): void;

  /**
   * Called inside save() just before handing the entity to AutoSaveManager.
   *
   * Override to perform entity-type-specific mutations (e.g. merging
   * client-side representation back onto the entity before persisting).
   */
  protected function beforeAutoSave(EntityInterface $entity): void {}

  public function resetCache(?array $ids = NULL): void {
  }

  /**
   * {@inheritdoc}
   *
   * @param string[]|null $ids
   *
   * @return array<string, \Drupal\Core\Entity\EntityInterface|null>
   */
  // @phpstan-ignore-next-line method.childParameterType
  public function loadMultiple(?array $ids = NULL): array {
    if ($ids === NULL) {
      return [];
    }
    $return = [];
    foreach ($ids as $id) {
      $return[$id] = $this->load($id);
    }
    return $return;
  }

  public function load($id) {
    \assert(\is_string($id));
    $stub = $this->createStub($id);
    $auto_save_entity = $this->autoSaveManager->getAutoSaveEntity($stub);
    if ($auto_save_entity->entity === NULL) {
      return NULL;
    }
    return $auto_save_entity->entity->enforceIsNew(FALSE);
  }

  public function loadUnchanged($id) {
    // Bypass AutoSaveManager's in-memory entity cache. Unlike load(), which may
    // return a memoized instance that callers have since mutated (e.g.
    // reconciliation calling setData()/clearData() on a StagedLanguageConfig-
    // Override), loadUnchanged() must return the entity as it exists in
    // persistent storage — matching Drupal core's storage contract.
    \assert(\is_string($id));
    $stub = $this->createStub($id);
    $auto_save_entity = $this->autoSaveManager->getAutoSaveEntity($stub, bypass_cache: TRUE);
    return $auto_save_entity->entity;
  }

  public function loadByProperties(array $values = []): array {
    throw new \LogicException('Cannot query ' . $this->entityType->id() . ' entities to load by properties.');
  }

  public function delete(array $entities): void {
    foreach ($entities as $entity) {
      $this->autoSaveManager->delete($entity);
    }
  }

  public function save(EntityInterface $entity): int {
    \assert($entity instanceof ConfigEntityInterface);
    $entity->enforceIsNew(FALSE);
    $entity->setOriginalId($entity->id());
    $return = SAVED_NEW;

    if ($entity->status() === TRUE) {
      $this->publish($entity);
      return SAVED_UPDATED;
    }

    $existing = $this->autoSaveManager->getAutoSaveEntity($entity);
    if ($existing->entity !== NULL) {
      $return = SAVED_UPDATED;
    }

    $this->beforeAutoSave($entity);
    $this->autoSaveManager->saveEntity($entity);
    return $return;
  }

  public function restore(EntityInterface $entity): void {
  }

  public function hasData(): bool {
    return FALSE;
  }

  public function getQuery($conjunction = 'AND') {
    throw new \LogicException('Cannot query ' . $this->entityType->id() . ' entities.');
  }

  public function getAggregateQuery($conjunction = 'AND') {
    throw new \LogicException('Cannot query ' . $this->entityType->id() . ' entities.');
  }

}
