<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Controls access to comments.
 *
 * Nobody may edit somebody else's message, not even a moderator: moderation
 * can remove a message, but never rewrite it. Like comment thread access,
 * this is independent of edit access to the commented surface.
 *
 * @see \Drupal\canvas\Entity\Comment
 * @see \Drupal\canvas\Entity\CommentThreadAccessControlHandler
 */
final class CommentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof Comment);
    $access = parent::checkAccess($entity, $operation, $account);
    $is_author = AccessResult::allowedIf((int) $entity->getOwnerId() === (int) $account->id())
      ->cachePerUser()
      ->addCacheableDependency($entity);

    return match ($operation) {
      'view' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, CommentThread::VIEW_PERMISSION)
      ),
      'update' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, CommentThread::CREATE_PERMISSION)
          ->andIf($is_author)
      ),
      'delete' => $access->orIf(
        AccessResult::allowedIfHasPermission($account, CommentThread::MODERATE_PERMISSION)
          ->orIf($is_author)
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
