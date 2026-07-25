<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Controls access to comment threads.
 *
 * Comment access is deliberately independent of edit access to the commented
 * surface, in both directions: commenting never requires edit access, and edit
 * access never grants commenting. This is what allows a comment-only role.
 *
 * @see \Drupal\canvas\Entity\CommentThread
 */
final class CommentThreadAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof CommentThread);
    $access = parent::checkAccess($entity, $operation, $account);

    return match ($operation) {
      'view' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, CommentThread::VIEW_PERMISSION)
      ),
      // Resolving and reopening a thread are updates: anybody who may comment
      // may also resolve.
      'update' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, CommentThread::CREATE_PERMISSION)
      ),
      'delete' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, CommentThread::MODERATE_PERMISSION)
      ),
      default => $access,
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, CommentThread::CREATE_PERMISSION);
  }

}
