<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\Utility\TypedDataHelper;

trait ConfigComponentTreeTrait {

  /**
   * @param array{uuid: string, inputs: string|array, component_id: string, parent_uuid?: string, slot?: string} $value
   *
   * @return \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem
   */
  private function conjureFieldItemObject(array $value): ComponentTreeItem {
    $field_item = TypedDataHelper::conjureFieldItemObject(ComponentTreeItem::PLUGIN_ID);
    assert($field_item instanceof ComponentTreeItem);
    $field_item->setValue($value);
    return $field_item;
  }

}
