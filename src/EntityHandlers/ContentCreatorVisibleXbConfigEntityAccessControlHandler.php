<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EntityHandlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\Access\XbUiAccessCheck;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentCreatorVisibleXbConfigEntityAccessControlHandler extends XbConfigEntityAccessControlHandler {

  final public function __construct(
    EntityTypeInterface $entity_type,
    ConfigManagerInterface $configManager,
    EntityTypeManagerInterface $entityTypeManager,
    private readonly XbUiAccessCheck $xbUiAccessCheck,
  ) {
    parent::__construct($entity_type, $configManager, $entityTypeManager);
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get(ConfigManagerInterface::class),
      $container->get(EntityTypeManagerInterface::class),
      $container->get(XbUiAccessCheck::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    assert($entity instanceof ConfigEntityInterface);
    return match($operation) {
      // We allow viewing these entities if the user has access to XB, and their
      // status is enabled.
      'view' => $this->xbUiAccessCheck->access($account)->andIf(AccessResult::allowedIf($entity->status())
        ->addCacheableDependency($entity)),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

}
