<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Field\FieldType;

use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\TypedDataTrait;

/**
 * An internal utility trait that can instantiate component trees.
 *
 * @internal
 *
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type SingleComponentInputArray from \Drupal\canvas\Plugin\DataType\ComponentInputs
 */
trait ComponentTreeItemListInstantiatorTrait {

  use TypedDataTrait;

  /**
   * Instantiates a (dangling) Canvas component tree.
   */
  protected function createDanglingComponentTreeItemList(FieldableEntityInterface|ComponentTreeEntityInterface|null $parent = NULL): ComponentTreeItemList {
    return self::staticallyCreateDanglingComponentTreeItemList($this->getTypedDataManager(), $parent);
  }

  /**
   * Instantiates a (dangling) Canvas component tree.
   *
   * "Dangling", in this case, means the component tree might not be attached to
   * any specific entity, unless $parent is passed.
   *
   * The component tree returned by this method uses the default validation
   * constraints at the "component tree" and "components instance" levels,
   * unless overridden.
   *
   * The default validation constraints are defined in:
   * - \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList::getConstraints()
   * - The FieldType attribute on
   *   \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem
   *
   * @see \Drupal\Core\TypedData\Validation\RecursiveContextualValidator::validateNode())
   */
  protected static function staticallyCreateDanglingComponentTreeItemList(TypedDataManagerInterface $typed_data_manager, FieldableEntityInterface|ComponentTreeEntityInterface|null $parent = NULL): ComponentTreeItemList {
    $list_definition = $typed_data_manager->createListDataDefinition('field_item:component_tree');
    \assert(\method_exists($list_definition, 'setCardinality'));
    $list_definition->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);
    $item_list = $typed_data_manager->createInstance('list', [
      'name' => NULL,
      'parent' => $parent?->getTypedData(),
      'data_definition' => $list_definition,
    ]);
    \assert($item_list instanceof ComponentTreeItemList);

    return $item_list;
  }

}
