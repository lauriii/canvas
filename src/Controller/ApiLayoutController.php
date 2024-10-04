<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiLayoutController {

  public function __invoke(FieldableEntityInterface $entity): JsonResponse {
    if ($entity->bundle() !== 'article') {
      throw new \LogicException('For now, this assumes the entity is an article!');
    }

    $item = $entity->get('field_xb_demo')->first();
    assert($item instanceof ComponentTreeItem);
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    $hydrated = $item->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    $hydrated_json = $hydrated->getValue()->getContent();
    assert(is_string($hydrated_json));

    $layout = [];
    $model = [];
    $decoded_tree = json_decode($tree->getValue(), TRUE);

    $this->buildLayout($layout, $model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID], json_decode($hydrated_json, TRUE)[ComponentTreeStructure::ROOT_UUID]);

    // @todo This now returns a mixture of pure tree structure with hydrated props values. Re-assess.
    $full_layout = [
      'uuid' => 'root',
      'nodeType' => 'root',
      'name' => 'root',
      'children' => $layout,
    ];
    return new JsonResponse([
      // Maps to the `tree` property of the XB field type.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
      // @todo Settle on final names and get in sync.
      'layout' => $full_layout,
      // Maps to the `props` property of the XB field type,.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues
      // @todo Settle on final names and get in sync.
      // If the model is empty return an empty object to ensure it is encoded as
      // an object and not empty array.
      'model' => empty($model) ? new \stdClass() : $model,
    ]);
  }

  private function buildLayout(array &$layout, array &$model, ComponentTreeItem $item, array $tree_tier, array $hydrated): void {
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $full_tree = json_decode($tree->getValue(), TRUE);
    // @todo tree recursion/slot support — this only supports a flat list — do this in https://www.drupal.org/project/experience_builder/issues/3446722
    foreach ($tree_tier as ['uuid' => $component_instance_uuid, 'component' => $component_type]) {
      $component_instance = [
        'uuid' => $component_instance_uuid,
        // Note: the UI expects slots in this component to be defined as `nodeType: slot`.
        'nodeType' => 'component',
        'type' => $component_type,
        'children' => [],
      ];
      if (isset($hydrated[$component_instance_uuid])) {
        $model[$component_instance_uuid] = $hydrated[$component_instance_uuid]['props'];
        $component_id = $tree->getComponentId($component_instance_uuid);
        // @todo the current quick-and-dirty UI PoC unfortunately prevents any prop from being named `name`, because it expects that to convey the component name
        $component_config = Component::loadByComponentMachineName($component_id);
        assert($component_config !== NULL);
        $model[$component_instance_uuid]['name'] = $component_config->label();
      }
      if (isset($full_tree[$component_instance_uuid])) {
        foreach ($full_tree[$component_instance_uuid] as $slot_name => $slot_children) {
          $component_instance_slot = [
            // @todo The client expects a UUID for slots, but we don't have one.
            'uuid' => $component_instance_uuid . '-slot-' . $slot_name,
            'name' => $slot_name,
            'nodeType' => 'slot',
            'children' => [],
          ];
          $this->buildLayout($component_instance_slot['children'], $model, $item, $slot_children, $hydrated[$component_instance_uuid]['slots'][$slot_name]);
          $component_instance['children'][] = $component_instance_slot;
        }
      }
      $layout[] = $component_instance;
    }
  }

}
