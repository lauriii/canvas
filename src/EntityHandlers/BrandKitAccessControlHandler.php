<?php

declare(strict_types=1);

namespace Drupal\canvas\EntityHandlers;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\canvas\Entity\BrandKit;

/**
 * Defines an access control handler for Brand Kit entities.
 *
 * Only the global brand kit exists; it is created by install or post_update.
 * Creation via the API is not allowed.
 */
final class BrandKitAccessControlHandler extends ContentCreatorVisibleCanvasConfigEntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::forbidden('Brand kits cannot be created via the API.');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'delete' && $entity->id() === BrandKit::GLOBAL_ID) {
      return AccessResult::forbidden('The global brand kit cannot be deleted');
    }
    return parent::checkAccess($entity, $operation, $account);
  }

}
