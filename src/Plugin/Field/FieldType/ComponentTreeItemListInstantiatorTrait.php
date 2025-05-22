<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\TypedDataTrait;

trait ComponentTreeItemListInstantiatorTrait {

  use TypedDataTrait;

  /**
   * Instantiates a (dangling) XB component tree.
   */
  protected function createDanglingComponentTreeItemList(?FieldableEntityInterface $parent = NULL): ComponentTreeItemList {
    return self::staticallyCreateDanglingComponentTreeItemList($this->getTypedDataManager(), $parent);
  }

  /**
   * Instantiates a (dangling) XB component tree.
   */
  protected static function staticallyCreateDanglingComponentTreeItemList(TypedDataManagerInterface $typed_data_manager, ?FieldableEntityInterface $parent = NULL): ComponentTreeItemList {
    $list_definition = $typed_data_manager->createListDataDefinition('field_item:component_tree');
    \assert(\method_exists($list_definition, 'setCardinality'));
    $list_definition->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $item_list = $typed_data_manager->createInstance('list', [
      'name' => NULL,
      'parent' => $parent?->getTypedData(),
      'data_definition' => $list_definition,
    ]);
    assert($item_list instanceof ComponentTreeItemList);

    return $item_list;
  }

}
