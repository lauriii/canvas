<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\InternalXbFieldNameResolver;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiLayoutController {

  public function __construct(private readonly AutoSaveManager $autoSaveManager) {
  }

  public function __invoke(FieldableEntityInterface $entity): JsonResponse {
    if ($body = $this->autoSaveManager->getAutoSaveData($entity)) {
      return new JsonResponse($body);
    }
    $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
    $item = $entity->get($field_name)->first();
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
      'nodeType' => 'region',
      'name' => 'content',
      'components' => $layout,
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
        'nodeType' => 'component',
        'uuid' => $component_instance_uuid,
        'type' => $component_type,
        'slots' => [],
      ];
      if (isset($hydrated[$component_instance_uuid])) {
        $model[$component_instance_uuid] = $hydrated[$component_instance_uuid]['props'];
      }
      if (isset($full_tree[$component_instance_uuid])) {
        foreach ($full_tree[$component_instance_uuid] as $slot_name => $slot_children) {
          $component_instance_slot = [
            'nodeType' => 'slot',
            'id' => $component_instance_uuid . '/' . $slot_name,
            'name' => $slot_name,
            'components' => [],
          ];
          $this->buildLayout($component_instance_slot['components'], $model, $item, $slot_children, $hydrated[$component_instance_uuid]['slots'][$slot_name]);
          $component_instance['slots'][] = $component_instance_slot;
        }
      }
      $layout[] = $component_instance;
    }
  }

}
