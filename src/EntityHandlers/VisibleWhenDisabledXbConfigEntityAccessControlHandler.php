<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EntityHandlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

final class VisibleWhenDisabledXbConfigEntityAccessControlHandler extends XbConfigEntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    assert($entity instanceof ConfigEntityInterface);
    return match($operation) {
      // We always allow viewing these entities if authenticated, even if disabled.
      'view' => AccessResult::allowedIf($account->isAuthenticated())->addCacheContexts(['user.roles:authenticated']),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

}
