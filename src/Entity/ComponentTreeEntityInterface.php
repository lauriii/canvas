<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Defines an interface for entities that store a component tree.
 */
interface ComponentTreeEntityInterface extends EntityInterface {

  /**
   * Gets the component tree stored by this entity.
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
   *   One (dangling) component tree.
   */
  public function getComponentTree(): ComponentTreeItemList;

}
