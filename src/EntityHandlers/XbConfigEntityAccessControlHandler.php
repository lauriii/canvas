<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EntityHandlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class XbConfigEntityAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  public function __construct(
    EntityTypeInterface $entity_type,
    private readonly ConfigManagerInterface $configManager,
  ) {
    parent::__construct($entity_type);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    // @phpstan-ignore-next-line
    return new static(
      $entity_type,
      $container->get(ConfigManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $adminPermission = $this->entityType->getAdminPermission();
    assert(is_string($adminPermission));
    return match($operation) {
      // Don't allow deleting if there are dependent entities.
      'delete' => AccessResult::forbiddenIf(!empty($this->configManager->getConfigDependencyManager()->getDependentEntities('config', $entity->getConfigDependencyName())), sprintf('There is other configuration depending on this %s.', $this->entityType->getSingularLabel()))
        ->orIf(AccessResult::allowedIfHasPermission($account, $adminPermission)),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

}
