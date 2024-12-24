<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\experience_builder\AutoSave\AutoSaveManager;
use Drupal\experience_builder\Entity\PageTemplate;
use Drupal\experience_builder\InternalXbFieldNameResolver;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiLayoutController {

  private array $regions;

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ThemeManagerInterface $themeManager,
  ) {}

  public function __invoke(FieldableEntityInterface $entity): JsonResponse {
    $template = PageTemplate::forActiveTheme();
    $theme = $this->themeManager->getActiveTheme()->getName();
    $this->regions = system_region_list($theme);

    // Ensure the Content region always exists.
    $this->regions['content'] ??= t('Content');

    if ($body = $this->autoSaveManager->getAutoSaveData($entity)) {
      ['layout' => $layout, 'model' => $model] = $body;

      // Override the full page template, if we have one.
      if ($template && $body = $this->autoSaveManager->getAutoSaveData($template)) {
        $layout = array_merge($layout, $body['layout']);
        $model += $body['model'];
      }
    }
    else {
      $model = [];

      // Build the content region.
      $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
      $tree = $entity->get($field_name)->first();
      assert($tree instanceof ComponentTreeItem);
      $layout = [$this->buildRegion('content', $tree, $model)];

      // If there is a template, build the other regions.
      if ($template) {
        $draft_template = $this->autoSaveManager->getAutoSaveData($template);
        if ($draft_template === NULL) {
          foreach ($template->getComponentTrees() as $region => $tree) {
            if ($region === 'content') {
              continue;
            }
            $layout[] = $this->buildRegion($region, $tree, $model);
          }
        }
        else {
          $layout = array_merge($layout, $draft_template['layout']);
          $model += $draft_template['model'];
        }
      }
    }

    if ($template) {
      // Ensure all regions exist, and reorder the layout to match theme order.
      $layout = array_combine(array_map(static fn($region) => $region['id'], $layout), $layout);
      $layout = array_map(fn(string $region) => $layout[$region] ?? $this->buildRegion($region), array_keys($this->regions));
    }

    return new JsonResponse([
      // Maps to the `tree` property of the XB field type.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
      // @todo Settle on final names and get in sync.
      'layout' => $layout,
      // Maps to the `props` property of the XB field type,.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues
      // @todo Settle on final names and get in sync.
      // If the model is empty return an empty object to ensure it is encoded as
      // an object and not empty array.
      'model' => empty($model) ? new \stdClass() : $model,
    ]);
  }

  private function buildRegion(string $id, ?ComponentTreeItem $item = NULL, ?array &$model = NULL): array {
    if ($item) {
      $tree = $item->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $hydrated = $item->get('hydrated');
      assert($hydrated instanceof ComponentTreeHydrated);
      $decoded_tree = json_decode($tree->getValue(), TRUE);
      $components = $this->buildLayout($model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID], $hydrated->getValue()->getTree()[ComponentTreeStructure::ROOT_UUID]);
    }
    else {
      $components = [];
    }

    return [
      'nodeType' => 'region',
      'id' => $id,
      'name' => $this->regions[$id],
      'components' => $components,
    ];
  }

  private function buildLayout(array &$model, ComponentTreeItem $item, array $tree_tier, array $hydrated): array {
    $layout = [];
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $full_tree = json_decode($tree->getValue(), TRUE);
    foreach ($tree_tier as ['uuid' => $component_instance_uuid, 'component' => $component_type]) {
      $component_instance = [
        'nodeType' => 'component',
        'uuid' => $component_instance_uuid,
        'type' => $component_type,
        'slots' => [],
      ];
      if (isset($hydrated[$component_instance_uuid])) {
        // @todo This needs to be smarter than checking props or settings.
        // Fix in https://drupal.org/i/3494684.
        $model[$component_instance_uuid] = $hydrated[$component_instance_uuid]['props'] ?? $hydrated[$component_instance_uuid]['settings'];
      }
      if (isset($full_tree[$component_instance_uuid])) {
        foreach ($full_tree[$component_instance_uuid] as $slot_name => $slot_children) {
          $component_instance['slots'][] = [
            'nodeType' => 'slot',
            'id' => $component_instance_uuid . '/' . $slot_name,
            'name' => $slot_name,
            'components' => $this->buildLayout($model, $item, $slot_children, $hydrated[$component_instance_uuid]['slots'][$slot_name]),
          ];
        }
      }
      $layout[] = $component_instance;
    }
    return $layout;
  }

}
