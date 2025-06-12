<?php

namespace Drupal\experience_builder\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\experience_builder\Entity\ComponentTreeEntityInterface;
use Drupal\experience_builder\Storage\ComponentTreeLoader;

/**
 * Checks access for editing an entity's component tree.
 *
 * @internal
 */
final class ComponentTreeEditAccessCheck implements AccessInterface {

  public function __construct(private readonly ComponentTreeLoader $componentTreeLoader) {}

  /**
   * Checks access for editing an entity's component tree.
   *
   * @todo remove the nullish argument in https://www.drupal.org/i/3529836
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity containing a component tree.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account being checked.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(?EntityInterface $entity, ?AccountInterface $account): AccessResultInterface {
    if ($entity instanceof FieldableEntityInterface || $entity instanceof ComponentTreeEntityInterface) {
      $tree = $this->componentTreeLoader->load($entity);
      // TRICKY: field access hooks must return AccessResult::forbidden() to
      // override the default field access. Then the forbidden field access's
      // reason would overwrite that of non-allowed entity access. Avoid that by
      // explicitly checking entity access and returning early.
      // @see \Drupal\Core\Field\FieldItemList::defaultAccess()
      $entity_access = $entity->access('update', $account, TRUE);
      if (!$entity_access->isAllowed()) {
        return $entity_access;
      }
      return $entity_access->andIf($tree->access('edit', $account, TRUE));
    }
    // No opinion.
    return AccessResult::neutral();
  }

}
