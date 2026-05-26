<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityDependency;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CanvasConfigEntityAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly ConfigManagerInterface $configManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    // @phpstan-ignore-next-line
    return new static(
      $entity_type,
      $container->get(ConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'delete') {
      return parent::checkAccess($entity, $operation, $account);
    }
    // Find any component config entities that depend on this entity.
    $dependent_entities = $this->configManager->getConfigDependencyManager()->getDependentEntities('config', $entity->getConfigDependencyName());
    if (\count($dependent_entities) === 0) {
      // There are no dependent entities so we can defer to the parent.
      return parent::checkAccess($entity, $operation, $account);
    }
    $adminPermission = $this->entityType->getAdminPermission();
    \assert(\is_string($adminPermission));
    // There are dependent entities, but we want to exclude any Component or
    // Folder entities from consideration here. Both implement
    // ::onDependencyRemoval() and can react to this entity being deleted
    // without requiring the deletion to be blocked.
    // @see \Drupal\canvas\Entity\Component::onDependencyRemoval()
    // @see \Drupal\canvas\Entity\Folder::onDependencyRemoval()
    $component_entity_type = $this->entityTypeManager->getDefinition(Component::ENTITY_TYPE_ID);
    \assert($component_entity_type instanceof ConfigEntityTypeInterface);
    $component_prefix = $component_entity_type->getConfigPrefix();

    $folder_entity_type = $this->entityTypeManager->getDefinition(Folder::ENTITY_TYPE_ID);
    \assert($folder_entity_type instanceof ConfigEntityTypeInterface);
    $folder_prefix = $folder_entity_type->getConfigPrefix();

    // Filter out dependent Component and Folder entities.
    $ignorable_config_entities = \array_filter($dependent_entities, static fn (ConfigEntityDependency $dependent_entity) => \str_starts_with($dependent_entity->getConfigDependencyName(), $component_prefix) || \str_starts_with($dependent_entity->getConfigDependencyName(), $folder_prefix));
    $dependent_entities = \array_diff_key($dependent_entities, $ignorable_config_entities);

    // Prevent deletion if additional dependent entities exist.
    return AccessResult::forbiddenIf(count($dependent_entities) > 0, \sprintf('There is other configuration depending on this %s.', $this->entityType->getSingularLabel()))
      ->orIf(AccessResult::allowedIfHasPermission($account, $adminPermission));
  }

}
