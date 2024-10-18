<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

// phpcs:disable
// @todo Remove this — this was added to avoid breaking the client while finalizing the server.
final class HardcodedPropsComponentTreeItem extends ComponentTreeItem {
  public array $hardcoded_props = [];
  public function resolveComponentProps(string $component_instance_uuid): array {
    // @todo the current quick-and-dirty UI PoC unfortunately prevents any prop from being named `name`, because it expects that to convey the component name — but it's not actually one of the props consumed by the SDC.
    return array_diff_key($this->hardcoded_props[$component_instance_uuid], ['name' => NULL]);
  }
}
// phpcs:enable

final class ApiPreviewController {

  use NotTheGoodAutoSaveTrait;

  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {}

  public function __invoke(Request $request, EntityInterface $entity): JsonResponse {
    $this->doAutoSave($entity, $request);
    ['layout' => $layout, 'model' => $model] = json_decode($request->getContent(), TRUE);
    $component_tree_field_item = $this->clientLayoutAndModelToXbField($layout, $model);

    $build = self::wrapComponentsForPreview($component_tree_field_item->toRenderable());
    $build['#prefix'] = '<div class="xb--sortable-list" data-xb-uuid="root">';
    $build['#suffix'] = '</div>';
    $build['#attached']['library'][] = 'experience_builder/preview';

    return new JsonResponse([
      'html' => $this->bareHtmlPageRenderer->renderBarePage($build, '', 'page')->getContent(),
    ]);
  }

  private static function wrapComponentsForPreview(array $build, ?string $component_instance_uuid = NULL): array {
    if (isset($build['#component'])) {
      assert(is_string($component_instance_uuid));
      $build['#prefix'] = sprintf('<div class="xb--sortable-item" data-xb-uuid="%s" data-xb-component-id="%s">', $component_instance_uuid, $build['#component']);
      $build['#suffix'] = '</div>';
    }
    foreach (Element::children($build) as $component_instance_uuid) {
      $build[$component_instance_uuid] = self::wrapComponentsForPreview($build[$component_instance_uuid], $component_instance_uuid);
    }
    if (isset($build['#slots'])) {
      foreach ($build['#slots'] as $slot_name => $slot) {
        $slot_uuid = $component_instance_uuid . '-slot-' . $slot_name;
        $build['#slots'][$slot_name] = self::wrapComponentsForPreview($slot, $slot_uuid);
        $build['#slots'][$slot_name]['#prefix'] = sprintf('<div class="xb--sortable-list" data-xb-uuid="%s" data-xb-component-id="%s"%s>',
          $slot_uuid,
          'slot',
          (array_key_exists('#plain_text', $build['#slots'][$slot_name]) || array_key_exists('#markup', $build['#slots'][$slot_name]))
            ? ' data-xb-slot-is-empty'
            : ''
        );
        $build['#slots'][$slot_name]['#suffix'] = '</div>';
      }
    }
    return $build;
  }

  /**
   * Transform the `layout` + `model` data structure that the client uses.
   *
   * This is the server side, so transform to the representation used by the
   * server-side field type. This allows reusing all of the field type
   * infrastructure, which is also used for
   * final rendering.
   *
   * @see \Drupal\experience_builder\Plugin\Field\FieldFormatter\NaiveComponentTreeFormatter
   * @todo Refactor/remove in https://www.drupal.org/project/experience_builder/issues/3467954.
   */
  private function clientLayoutAndModelToXbField(array $layout, array $model): ComponentTreeItem {
    $field_item_definition = $this->typedDataManager->createDataDefinition('field_item:component_tree');
    // @phpstan-ignore-next-line
    $field_item_definition->setClass(HardcodedPropsComponentTreeItem::class);
    $component_tree_field_item = $this->typedDataManager->createInstance('field_item:component_tree', [
      'name' => NULL,
      'parent' => NULL,
      'data_definition' => $field_item_definition,
    ]);

    // Transform `layout` to `tree`.
    // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
    $tree = [ComponentTreeStructure::ROOT_UUID => []];
    self::clientLayoutToServerTree($layout, ComponentTreeStructure::ROOT_UUID, NULL, $tree);

    // This uses a partial override of the XB field type, because the client is
    // sending explicit prop values in its `model`, not prop sources. Use these
    // directly.
    // @see \Drupal\experience_builder\Controller\HardcodedPropsComponentTreeItem::resolveComponentProps()
    assert($component_tree_field_item instanceof HardcodedPropsComponentTreeItem);
    $component_tree_field_item->setValue([
      'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT),
    ]);
    $component_tree_field_item->hardcoded_props = $model;

    return $component_tree_field_item;
  }

  /**
   * @todo Refactor/remove in https://www.drupal.org/project/experience_builder/issues/3467954.
   */
  private static function clientLayoutToServerTree(array $layout, string $parent_uuid, ?string $parent_slot, array &$tree) : void {
    foreach ($layout['children'] as $child) {
      if ($child['nodeType'] === 'slot') {
        // @todo This indicates the client model does not quite make sense: SDC slots do NOT have UUIDs, but names! Fix in https://www.drupal.org/project/experience_builder/issues/3467954.
        self::clientLayoutToServerTree($child, $parent_uuid, $child['name'], $tree);
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
        self::clientLayoutToServerTree($child, $child['uuid'], NULL, $tree);
      }
    }
  }

}
