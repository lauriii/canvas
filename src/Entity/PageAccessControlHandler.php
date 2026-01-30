<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

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
    \assert($entity instanceof Page);
    $access = parent::checkAccess($entity, $operation, $account);

    return match ($operation) {
      'view' => $access
        ->orIf(AccessResult::allowedIfHasPermissions(
          $account,
          [Page::CREATE_PERMISSION, Page::EDIT_PERMISSION, Page::DELETE_PERMISSION],
          'OR'
        ))
        ->orIf(
          AccessResult::allowedIf($entity->isPublished())
            ->andIf(AccessResult::allowedIfHasPermission($account, 'access content'))
            ->addCacheableDependency($entity)
        ),
      'update', 'view all revisions', 'view revision', 'revert' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, Page::EDIT_PERMISSION)
      ),
      'delete' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, Page::DELETE_PERMISSION)
      ),
      default => $access,
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, Page::CREATE_PERMISSION);
  }

}
