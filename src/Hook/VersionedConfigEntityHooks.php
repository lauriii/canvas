<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Entity\VersionedConfigEntityInterface;
use Drupal\canvas\EntityHandlers\VersionedConfigEntityStorage;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * @internal
 */
final class VersionedConfigEntityHooks {

  /**
   * Implements hook_entity_type_alter().
   */
  #[Hook('entity_type_alter')]
  public static function entityTypeAlter(array &$entity_types): void {
    foreach ($entity_types as $entity_type) {
      // Every versioned config entity needs the storage handler that realigns
      // the loaded version after a config import overwrites `active_version`.
      // Entity types should declare it directly (Component does, for
      // discoverability); this is the safety net for any that only have the
      // default storage. An entity type shipping its own handler keeps it, and
      // can extend VersionedConfigEntityStorage if it needs this behavior too.
      // @see \Drupal\canvas\Entity\Component
      $class = $entity_type->getClass();
      if (\is_a($class, VersionedConfigEntityInterface::class, TRUE) && $entity_type->getStorageClass() === ConfigEntityStorage::class) {
        $entity_type->setStorageClass(VersionedConfigEntityStorage::class);
      }
    }
  }

}
