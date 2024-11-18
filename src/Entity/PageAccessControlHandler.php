<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

final class PageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    $result = parent::checkAccess($entity, $operation, $account);
    if ($operation === 'view' && $result->isNeutral()) {
      $result = $result->orIf(
        AccessResult::allowedIfHasPermission($account, 'access content')
      );
    }
    return $result;
  }

}
