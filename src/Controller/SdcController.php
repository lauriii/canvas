<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Asset\AssetCollectionRendererInterface;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropShape;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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

final class SdcController extends ControllerBase {

  /**
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentPluginManager
   * @param \Drupal\Core\Render\RendererInterface $renderer
   * @param \Drupal\Core\Asset\AssetResolverInterface $assetResolver
   * @param \Drupal\Core\Asset\AssetCollectionRendererInterface $cssCollectionRenderer
   * @param \Drupal\Core\Render\BareHtmlPageRendererInterface $bareHtmlPageRenderer
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   */
  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RendererInterface $renderer,
    protected AssetResolverInterface $assetResolver,
    #[Autowire(service: 'asset.css.collection_renderer')]
    protected AssetCollectionRendererInterface $cssCollectionRenderer,
    protected readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly TypedDataManagerInterface $typedDataManager,
  ) {}

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

  /**
   * Gets an array of single directory components in an xb-friendly form.
   *
   * @return array<string, mixed>
   *   The array or single directory components.
   */
  private function getComponentsList(): array {
    $component_list = [];
    foreach (Component::loadMultiple() as $component) {
      $component_plugin = $this->componentPluginManager->find($component->getComponentMachineName());
      $keyed_choices = [];
      foreach (PropShape::getComponentProps($component_plugin) as $component_prop_expression => $prop_shape) {
        $storable_prop_shape = $prop_shape->getStorage();
        // @todo Remove this once every SDC prop shape can be stored.
        // @todo Create a status report that lists which SDC props are not storable.
        if (!$storable_prop_shape) {
          continue;
        }
        $static_prop_source = $storable_prop_shape->toStaticPropSource();
        $component_prop = ComponentPropExpression::fromString($component_prop_expression);

        $keyed_choices[$component_prop->propName] = [
          'expression' => (string) $storable_prop_shape->fieldTypeProp,
          'sourceType' => $static_prop_source->getSourceType(),
          'required' => in_array($component_prop->propName, $component_plugin->metadata->schema['required'] ?? [], TRUE),
        ];
        $prop_info = ($component_plugin->metadata->schema['properties'] ?? [])[$component_prop->propName];
        // Provide defaults if they are specified on the SDC *or* if they are
        // required. If they're required and no example is specified: generate a
        // value.
        if ($keyed_choices[$component_prop->propName]['required'] || (isset($prop_info['examples']) && isset($prop_info['examples'][0]))) {
          // @todo Revisit in https://www.drupal.org/project/experience_builder/issues/3455942.
          $keyed_choices[$component_prop->propName]['default_values'] = $prop_info['examples'][0] ?? $this->getDefaultValueFromPropInfo($prop_info);
        }
        if ($storable_prop_shape->fieldStorageSettings !== NULL) {
          $keyed_choices[$component_prop->propName]['sourceTypeSettings']['storage'] = $storable_prop_shape->fieldStorageSettings;
        }
        if ($storable_prop_shape->fieldInstanceSettings !== NULL) {
          $keyed_choices[$component_prop->propName]['sourceTypeSettings']['instance'] = $storable_prop_shape->fieldInstanceSettings;
        }
      }
      $assets = AttachedAssets::createFromRenderArray([
        '#attached' => [
          // @see \Drupal\Core\Plugin\Component::getLibraryName()
          'library' => ['core/components.' . str_replace(':', '--', $component_plugin->getPluginId())],
        ],
      ]);

      $css_array = $this->cssCollectionRenderer->render($this->assetResolver->getCssAssets($assets, FALSE));
      $css = $this->renderer->render($css_array);

      $default_markup = (string) $this->prepareRenderArray($component_plugin->getPluginId())['markup'];

      $component_list[] = [
        'id' => $component_plugin->getPluginId(),
        'name' => $component_plugin->metadata->name,
        'metadata' => $component_plugin->metadata,
        'field_data' => $keyed_choices,
        // A pre-rendered version of the component is provided so no requests
        // are needed when adding it to the layout.
        'default_markup' => $css . $default_markup,
      ];
    }

    // Component array is keyed by ID.
    return array_combine(array_column($component_list, 'id'), $component_list);
  }

  /**
   * Provides a list of single directory components as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The components list.
   */
  public function components() {
    return new JsonResponse($this->getComponentsList());
  }

  /**
   * Provides one single directory component as JSON.
   *
   * @param string $component_id
   *   The component ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function component(string $component_id): JsonResponse {
    $components = array_filter($this->getComponentsList(), fn($component) => $component['id'] === $component_id);
    assert(!empty($components));
    return new JsonResponse(reset($components));
  }

  /**
   * Renders an SDC and provides the markup in a JSON response.
   *
   * This currently renders the component with default prop values. To render
   * with other prop values, this will need to be expanded.
   *
   * @param string $component_id
   *   The component ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function renderComponent(string $component_id): JsonResponse {
    return new JsonResponse($this->prepareRenderArray($component_id));
  }

  public function prepareRenderArray(string $component_id): array {
    $build = [
      '#type' => 'component',
      '#component' => $component_id,
    ];

    $component_info = array_filter(
      $this->componentPluginManager->getAllComponents(),
      fn($component) => $component->getPluginId() === $component_id,
    );
    assert(!empty($component_info));
    $component = array_values($component_info)[0];
    $metadata = $component->metadata;
    $properties = $metadata->schema['properties'] ?? [];
    foreach ($properties as $prop_name => $prop_info) {
      // Provide defaults if they are specified on the SDC *or* if they are
      // required. If they're required and no example is specified: generate a
      // value.
      // @todo Revisit in https://www.drupal.org/project/experience_builder/issues/3455942.
      if ((isset($prop_info['examples']) && isset($prop_info['examples'][0])) || in_array($prop_name, $metadata->schema['required'] ?? [])) {
        self::populatePropValues($build, [], $prop_name, $prop_info);
      }
    }

    $rendered_component = $this->renderer->render($build);
    unset($build['#props']['attributes']);
    return [
      'id' => $component_id,
      'markup' => $rendered_component,
      'props' => $build['#props'] ?? [],
      'metadata' => $metadata,
    ];
  }

  public function layout(FieldableEntityInterface $entity): JsonResponse {
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
      'model' => $model,
    ]);
  }

  private static function clientLayoutToServerTree(array $layout, string $parent_uuid, ?string $parent_slot, array &$tree) : void {
    foreach ($layout['children'] as $child) {
      if ($child['nodeType'] === 'slot') {
        // @todo This indicates the client model does not quite make sense: SDC slots do NOT have UUIDs, but names!
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

  /**
   * Transform the `layout` + `model` data structure that the client uses.
   *
   * This is the server side, so transform to the representation used by the
   * server-side field type. This allows reusing all of the field type
   * infrastructure, which is also used for
   * final rendering.
   *
   * @see \Drupal\experience_builder\Plugin\Field\FieldFormatter\NaiveComponentTreeFormatter
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

  public function preview(Request $request, EntityInterface $entity): JsonResponse {
    ['layout' => $layout, 'model' => $model] = json_decode($request->getContent(), TRUE);
    $component_tree_field_item = $this->clientLayoutAndModelToXbField($layout, $model);

    $build = self::wrapComponentsForPreview($component_tree_field_item->toRenderable());
    $build['#prefix'] = '<div class="sortable-list" data-xb-uuid="root">';
    $build['#suffix'] = '</div>';
    $build['#attached']['library'][] = 'experience_builder/preview';

    return new JsonResponse([
      'html' => $this->bareHtmlPageRenderer->renderBarePage($build, '', 'page')->getContent(),
    ]);
  }

  private static function wrapComponentsForPreview(array $build, ?string $component_instance_uuid = NULL): array {
    if (isset($build['#component'])) {
      assert(is_string($component_instance_uuid));
      $build['#prefix'] = sprintf('<div class="sortable-item" data-xb-uuid="%s" data-xb-type="%s">', $component_instance_uuid, $build['#component']);
      $build['#suffix'] = '</div>';
    }
    foreach (Element::children($build) as $component_instance_uuid) {
      $build[$component_instance_uuid] = self::wrapComponentsForPreview($build[$component_instance_uuid], $component_instance_uuid);
    }
    if (isset($build['#slots'])) {
      foreach ($build['#slots'] as $slot_name => $slot) {
        $slot_uuid = $component_instance_uuid . '-slot-' . $slot_name;
        $build['#slots'][$slot_name] = self::wrapComponentsForPreview($slot, $slot_uuid);
        $build['#slots'][$slot_name]['#prefix'] = sprintf('<div class="sortable-list" data-xb-uuid="%s" data-xb-type="%s">', $slot_uuid, 'slot');
        $build['#slots'][$slot_name]['#suffix'] = '</div>';
      }
    }
    return $build;
  }

  /**
   * Assign values to props in the SDC render array.
   *
   * @param array<string, mixed> $build
   *   The render array.
   * @param array<string, mixed> $values
   *   Already defined prop values.
   * @param string $prop_name
   *   The prop being checked.
   * @param array<string, mixed> $prop_info
   *   The prop's metadata.
   */
  private function populatePropValues(array &$build, array $values, string $prop_name, array $prop_info): void {
    $value = '';
    if (isset($prop_info['examples'])) {
      if ($values) {
        $value = $prop_info['examples'][$values[$prop_name]] ?? $prop_info['examples'][0];
      }
      else {
        $value = $prop_info['examples'][0];
      }
    }
    else {
      if ($prop_name === 'attributes') {
        return;
      }
      $value = $this->getDefaultValueFromPropInfo($prop_info);
    }

    $build['#props'][$prop_name] = $value;
  }

  /**
   * @todo Remove in https://www.drupal.org/project/experience_builder/issues/3455942.
   */
  public function getDefaultValueFromPropInfo(array $prop_info): array|string|object|int {
    $value = '';
    if (isset($prop_info['enum'])) {
      $value = $prop_info['enum'][0];
    }
    elseif (isset($prop_info['type']) && $prop_info['type'] === 'integer') {
      $value = 14;
    }
    elseif (isset($prop_info['type']) && $prop_info['type'] === 'string') {
      $value = 'Lorem Ipsum';
      if (isset($prop_info['pattern'])) {
        // @todo if this were to work we would need to create a string that
        // matched the regex... perhaps at that point we just insist the
        // creators add their own defaults?
      }
    }
    elseif (isset($prop_info['$ref'])) {
      $value = [];
      $schema = json_decode(file_get_contents($prop_info['$ref']) ?: '{}', TRUE);
      if (isset($schema['properties'])) {
        foreach ($schema["properties"] as $sub_property => $sub_property_data) {
          $value[$sub_property] = $this->getDefaultValueFromPropInfo($sub_property_data);
        }
      }
      else {
        $value = $this->getDefaultValueFromPropInfo($schema);
      }
    }
    elseif (isset($prop_info['type']) &&$prop_info['type'][0] === 'object') {
      $value = [];
    }
    elseif (isset($prop_info['type'][1]) && $prop_info['type'][1] === 'object') {
      if (isset($prop_info['format']) && $prop_info['format'] === 'uri') {
        $value = 'https://drupal.org';
      }
      elseif ($prop_info['type'][0] !== 'string') {
        $value = new $prop_info['type'][0]();
      }
    }

    return $value;
  }

}
