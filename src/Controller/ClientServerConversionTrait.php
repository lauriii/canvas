<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;

/**
 * @internal
 * @phpstan-import-type ComponentTreeStructureArray from \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
 */
trait ClientServerConversionTrait {

  /**
   * @todo Refactor/remove in https://www.drupal.org/project/experience_builder/issues/3467954.
   *
   * @return array{0: ComponentTreeStructureArray, 1: \Symfony\Component\Validator\ConstraintViolationListInterface}
   */
  private static function clientLayoutToServerTree(array $layout) : array {
    // Transform client-side representation to server-side representation.
    $tree = self::doClientLayoutToServerTree(
      layout: $layout,
      // Empty component tree to populate using $layout.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
      tree: [ComponentTreeStructure::ROOT_UUID => []],
      // The entire component tree is nested under the reserved root UUID.
      parent_uuid: ComponentTreeStructure::ROOT_UUID,
    );

    // Validate it.
    $definition = DataDefinition::create('component_tree_structure');
    $component_tree_structure = new ComponentTreeStructure($definition, 'component_tree_structure');
    $component_tree_structure->setValue(json_encode($tree, JSON_UNESCAPED_UNICODE));
    $violations = $component_tree_structure->validate();

    return [$tree, $violations];
  }

  /**
   * phpcs:ignore Drupal.Commenting.DataTypeNamespace.DataTypeNamespace
   * @return ComponentTreeStructureArray
   */
  private static function doClientLayoutToServerTree(array $layout, ?string $parent_uuid = NULL, ?string $parent_slot = NULL, ?array $tree = NULL) : array {
    foreach ($layout['children'] as $child) {
      if ($child['nodeType'] === 'slot') {
        // @todo This indicates the client model does not quite make sense: SDC slots do NOT have UUIDs, but names! Fix in https://www.drupal.org/project/experience_builder/issues/3467954.
        $tree = self::doClientLayoutToServerTree($child, $parent_uuid, $child['name'], $tree);
        continue;
      }

      // Root level.
      if (!isset($parent_slot)) {
        $tree[$parent_uuid][] = [
          'uuid' => $child['uuid'],
          'component' => $child['type'],
        ];
      }
      // All other levels.
      else {
        $tree[$parent_uuid][$parent_slot][] = [
          'uuid' => $child['uuid'],
          'component' => $child['type'],
        ];
      }
      if (!empty($child['children'])) {
        $tree = self::doClientLayoutToServerTree($child, $child['uuid'], NULL, $tree);
      }
    }
    assert(!is_null($tree));
    return $tree;
  }

}
