<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\experience_builder\Audit\ComponentTreeDependencyRepository;
use Drupal\field\FieldConfigInterface;

/**
 * Defines hooks that interact with the component tree dependency repository.
 *
 * @todo remove when https://drupal.org/i/3481903 is fixed and directly
 *   implement the hooks in
 *   \Drupal\experience_builder\Audit\ComponentTreeDependencyRepository
 */
final class ComponentTreeDependencyRepositoryHooks {

  public function __construct(
    private readonly ComponentTreeDependencyRepository $dependencyRepository,
  ) {
  }

  #[Hook('entity_update')]
  #[Hook('entity_insert')]
  public function onEntityInsertUpdate(EntityInterface $entity): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $this->dependencyRepository->onEntityUpdateOrInsert($entity);
  }

  #[Hook('entity_delete')]
  public function onEntityDelete(EntityInterface $entity): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $this->dependencyRepository->onEntityDelete($entity);
  }

  #[Hook('entity_revision_delete')]
  public function onEntityRevisionDelete(EntityInterface $entity): void {
    if (!$entity instanceof FieldableEntityInterface || !$entity->getEntityType()->isRevisionable()) {
      return;
    }
    $this->dependencyRepository->onEntityRevisionDelete($entity);
  }

  #[Hook('field_config_delete')]
  public function onFieldConfigDelete(FieldConfigInterface $field): void {
    $this->dependencyRepository->onFieldConfigDelete($field);
  }

}
