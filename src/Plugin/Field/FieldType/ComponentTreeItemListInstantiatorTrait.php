<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Field\FieldType;

use Drupal\Component\Graph\Graph;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\TypedData\TypedDataTrait;

/**
 * @phpstan-import-type ComponentTreeItemListArray from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type ComponentTreeItemArray from \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList
 * @phpstan-import-type SingleComponentInputArray from \Drupal\experience_builder\Plugin\DataType\ComponentInputs
 */
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

  /**
   * @phpstan-param ComponentTreeItemListArray $tree
   *
   * @phpstan-return array<string, ComponentTreeItemArray>
   *
   * @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItemList::constructDepthFirstGraph()
   */
  protected static function generateComponentTreeKeys(array $tree): array {
    $graph = [];
    // First construct a graph so we can order the component instances (i.e.
    // items in a ComponentTreeItemList) based on their depth.
    foreach ($tree as $value) {
      $parent_and_slot_reference = \sprintf('%s:%s', $value['parent_uuid'] ?? NULL, $value['slot'] ?? NULL);
      \assert(\array_key_exists('uuid', $value));
      $graph[$parent_and_slot_reference]['edges'][$value['uuid']] = TRUE;
      $graph[$value['uuid']]['edges'][$parent_and_slot_reference] = TRUE;
    }

    // Then sort the graph.
    $sorted_graph = (new Graph($graph))->searchAndSort();
    \uasort($sorted_graph, SortArray::sortByWeightElement(...));

    // Keep track of the component items by their UUID.
    $uuid_lookup = \array_combine(\array_column($tree, 'uuid'), $tree);
    $keyed_tree = [];
    $parent_key_lookup = [];

    // Loop over each vertex in the graph and construct a keyed array.
    foreach ($sorted_graph as $vertex_key => $graph) {
      if (!\str_contains($vertex_key, ':')) {
        // Ignore reverse lookups entries.
        continue;
      }
      [$parent_uuid] = \explode(':', $vertex_key, 2);
      foreach (\array_keys($graph['edges']) as $delta => $edge) {
        // Get the component tree item (component instance) for this edge.
        $item = $uuid_lookup[$edge];

        // Build a key based on:
        // - the parent key
        // - the slot name
        // - this item's delta in the slot.
        // We implode the keys with ':' rather than '.' because '.' has special
        // meaning in the config API.
        $key = \implode(':', \array_filter([
          $parent_key_lookup[$parent_uuid] ?? NULL,
          $item['slot'] ?? NULL,
          $delta,
        ], static fn (string|int|null $key_part) => $key_part !== NULL));

        // Keep track of this item's key so any children can use it to construct
        // their key.
        $parent_key_lookup[$edge] = $key;

        // Store the component tree item (component instance) against its key.
        $keyed_tree[$key] = $item;
      }
    }

    // Order the items by the key.
    \ksort($keyed_tree);
    return $keyed_tree;
  }

}
