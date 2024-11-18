<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Core\TypedData\TypedDataTrait;

trait ComponentTreeItemInstantiatorTrait {

  use TypedDataTrait;

  /**
   * Instantiates a single (dangling) XB component tree field item object.
   */
  protected function createDanglingComponentTree(): ComponentTreeItem {
    $typed_data_manager = $this->getTypedDataManager();
    $field_item_definition = $typed_data_manager->createDataDefinition('field_item:component_tree');
    $field_item = $typed_data_manager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    assert($field_item instanceof ComponentTreeItem);

    return $field_item;
  }

}
