<?php

declare(strict_types=1);

namespace Drupal\canvas\Entity;

use Drupal\Core\Entity\EntityInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;

/**
 * Defines an interface for entities that store a component tree.
 */
interface ComponentTreeEntityInterface extends EntityInterface {

  /**
   * Gets the component tree stored by this entity.
   *
   * @return \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
   *   One (dangling) component tree.
   */
  public function getComponentTree(): ComponentTreeItemList;

  /**
   * @see \Drupal\Core\Field\FieldItemList::setValue()
   * @see docs/data-model.md#3.1.2
   */
  public function setComponentTree(array $values): self;

}
