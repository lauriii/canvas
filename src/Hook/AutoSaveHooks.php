<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\experience_builder\AutoSave\AutoSaveManager;

/**
 * @file
 * Hook implementations for XB's auto-save functionality.
 *
 * @see \Drupal\experience_builder\AutoSave\AutoSaveManager
 */
class AutoSaveHooks {

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
  ) {
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->autoSaveManager->delete($entity);
  }

}
