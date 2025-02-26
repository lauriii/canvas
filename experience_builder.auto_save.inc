<?php

declare(strict_types=1);

/**
 * @file
 * Hook implementations for XB's auto-save functionality.
 *
 * @see \Drupal\experience_builder\AutoSave\AutoSaveManager
 */

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;

/**
 * Implements hook_entity_update().
 */
function experience_builder_entity_update(EntityInterface $entity): void {
  if ($entity instanceof ConfigEntityInterface) {
    // Scope this to config entities only - to avoid potential data-loss for
    // content entities where a user could update the entity via a regular edit
    // form whilst a second user is editing the layout/model via XB.
    // @todo Mitigate this in https://drupal.org/i/3492059
    \Drupal::service(AutoSaveManager::class)->delete($entity);
  }
}

/**
 * Implements hook_entity_delete().
 */
function experience_builder_entity_delete(EntityInterface $entity): void {
  \Drupal::service(AutoSaveManager::class)->delete($entity);
}
