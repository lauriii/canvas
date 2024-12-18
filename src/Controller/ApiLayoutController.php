<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  public function __construct(
    private readonly AutoSaveManager $autoSaveManager,
    private readonly ThemeManagerInterface $themeManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function __invoke(FieldableEntityInterface $entity): JsonResponse {
    if ($body = $this->autoSaveManager->getAutoSaveData($entity)) {
      // @todo Once auto-save stores regions other than content separately,
      // we will need to add global regions here.
      // @see https://www.drupal.org/project/experience_builder/issues/3494114
      return new JsonResponse($body);
    }
    $field_name = InternalXbFieldNameResolver::getXbFieldName($entity);
    $item = $entity->get($field_name)->first();
    assert($item instanceof ComponentTreeItem);
    $tree = $item->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    $hydrated = $item->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);

    $layout = [];
    $model = [];
    $decoded_tree = json_decode($tree->getValue(), TRUE);

    $this->buildLayout($layout, $model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID], $hydrated->getValue()->getTree()[ComponentTreeStructure::ROOT_UUID]);

    $layout = $this->addGlobalRegions($layout, $model);

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

  private function buildLayout(array &$layout, array &$model, ComponentTreeItem $item, array $tree_tier, array $hydrated): void {
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

  private function addGlobalRegions(array $main, array &$model): array {
    $theme = $this->themeManager->getActiveTheme();
    $template = $this->entityTypeManager->getStorage(PageTemplate::PLUGIN_ID)->load($theme->getName());
    $region_names = \system_region_list($theme->getName());
    $layout = [];
    if ($template instanceof PageTemplate && $template->status()) {
      // We have an enabled template, let's use that.
      foreach ($template->getComponentTrees() as $region => $item) {
        if ($region === 'content') {
          $layout[] = [
            'uuid' => $region,
            'nodeType' => 'region',
            'name' => $region_names[$region],
            'components' => $main,
          ];
          continue;
        }
        $region_layout = [];
        \assert($item instanceof ComponentTreeItem);
        $tree = $item->get('tree');
        \assert($tree instanceof ComponentTreeStructure);
        $decoded_tree = \json_decode($tree->getValue(), TRUE);
        $hydrated = $item->get('hydrated');
        \assert($hydrated instanceof ComponentTreeHydrated);
        $this->buildLayout($region_layout, $model, $item, $decoded_tree[ComponentTreeStructure::ROOT_UUID], $hydrated->getValue()->getTree()[ComponentTreeStructure::ROOT_UUID]);
        $layout[] = [
          'uuid' => $region,
          'nodeType' => 'region',
          'name' => $region_names[$region],
          'components' => $region_layout,
        ];
      }
      return $layout;
    }

    // Fallback to empty regions.
    $regions = $theme->getRegions();
    if (\count($regions) === 0) {
      // We need to support at least a content region.
      $layout[] = [
        'uuid' => 'content',
        'nodeType' => 'region',
        'name' => t('Content'),
        'components' => $main,
      ];
      return $layout;
    }

    foreach ($regions as $region) {
      $layout[] = [
        'uuid' => $region,
        'nodeType' => 'region',
        'name' => $region_names[$region],
        'components' => $region == 'content' ? $main : [],
      ];
    }
    return $layout;
  }

}
