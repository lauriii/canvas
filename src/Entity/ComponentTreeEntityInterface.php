<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Defines an interface for entities that store a component tree.
 */
interface ComponentTreeEntityInterface {

  /**
   * Gets the component tree stored by this entity.
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
   *   One (dangling) component tree.
   */
  public function getComponentTree(): ComponentTreeItem;

}
