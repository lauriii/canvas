<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\VersionedConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityStorage;

/**
 * Storage handler for versioned config entities.
 *
 * @internal
 */
final class VersionedConfigEntityStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function updateFromStorageRecord(ConfigEntityInterface $entity, array $values): ConfigEntityInterface {
    $entity = parent::updateFromStorageRecord($entity, $values);
    // A config import updates the existing entity in place: core copies each
    // exported property of the sync record onto it, including `active_version`.
    // The loaded version is derived, non-exported state set once in the
    // constructor, so the copy leaves it pointing at what has just become a
    // historical version — which makes ::preSave() refuse to save. This is the
    // one context where the loaded version must follow `active_version`, so
    // re-derive it here rather than in the general-purpose ::set().
    // @see \Drupal\Core\Config\Entity\ConfigEntityStorage::updateFromStorageRecord()
    // @see \Drupal\canvas\Entity\VersionedConfigEntityBase::preSave()
    \assert($entity instanceof VersionedConfigEntityInterface);
    $entity->resetToActiveVersion();
    return $entity;
  }

}
