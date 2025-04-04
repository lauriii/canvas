<?php

declare(strict_types=1);

namespace Drupal\experience_builder\EntityHandlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

final class ContentCreatorVisibleXbConfigEntityAccessControlHandler extends XbConfigEntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    return match($operation) {
      // We always allow viewing these entities.
      'view' => AccessResult::allowed(),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

}
