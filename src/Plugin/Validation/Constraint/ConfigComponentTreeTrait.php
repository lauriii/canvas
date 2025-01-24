<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;

trait ConfigComponentTreeTrait {

  /**
   * @param array{tree: string, inputs: string} $value
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
   */
  private function conjureFieldItemObject(array $value): ComponentTreeItem {
    assert($this->typedDataManager instanceof TypedDataManagerInterface);
    $field_item_definition = $this->typedDataManager->createDataDefinition('field_item:component_tree');
    $field_item = $this->typedDataManager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);
    $field_item->setValue($value);
    assert($field_item instanceof ComponentTreeItem);
    return $field_item;
  }

}
