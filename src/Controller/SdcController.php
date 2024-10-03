<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\experience_builder\AssetRenderer;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;
use Symfony\Component\HttpFoundation\JsonResponse;

final class SdcController extends ControllerBase {

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RendererInterface $renderer,
    private readonly AssetRenderer $assetRenderer,
    protected readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    protected readonly FieldForComponentSuggester $fieldForComponentSuggester,
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
      // Hide disabled components.
      if (!$component->status()) {
        continue;
      }
      $component_plugin = $this->componentPluginManager->find($component->getComponentMachineName());
      $keyed_choices = [];
      $suggestions = $this->fieldForComponentSuggester->suggest($component_plugin->getPluginId(), EntityDataDefinition::create('node', 'article'));
      $dynamic_prop_source_candidates = [];
      $default_props_for_default_markup = [];
      foreach (PropShape::getComponentProps($component_plugin) as $component_prop_expression => $prop_shape) {
        $storable_prop_shape = $prop_shape->getStorage();
        // @todo Remove this once every SDC prop shape can be stored.
        // @todo Create a status report that lists which SDC props are not storable.
        if (!$storable_prop_shape) {
          continue;
        }
        $static_prop_source = $storable_prop_shape->toStaticPropSource();
        $component_prop = ComponentPropExpression::fromString($component_prop_expression);
        if (isset($suggestions[$component_prop_expression])) {
          $dynamic_prop_source_candidates[$component_prop->propName] = array_map(
            fn (FieldPropExpression|FieldObjectPropsExpression|ReferenceFieldPropExpression $expr) => (string) $expr,
            $suggestions[$component_prop_expression]['instances']
          );
        }
        $keyed_choices[$component_prop->propName] = [
          'expression' => (string) $storable_prop_shape->fieldTypeProp,
          'sourceType' => $static_prop_source->getSourceType(),
          'required' => in_array($component_prop->propName, $component_plugin->metadata->schema['required'] ?? [], TRUE),
        ];
        $prop_info = ($component_plugin->metadata->schema['properties'] ?? [])[$component_prop->propName];
        // Defaults are guaranteed to exist for required props, may exist for
        // optional props. When an optional prop has no default value, the value
        // stored as the default in the Component config entity is NULL.
        // @see \Drupal\experience_builder\Plugin\ComponentPluginManager::componentMeetsRequirements()
        $is_image = isset($prop_info['$ref']) && $prop_info['$ref'] === 'json-schema-definitions://experience_builder.module/image';
        // @todo Add support for default images in SDCs: /components/image/image.component.yml. (And entity references in general.)
        // @see \Drupal\experience_builder\Entity\Component::getDefaultsForComponentPlugin
        $is_datetime = isset($prop_info['format']) && $prop_info['format'] === 'date-time';
        // @todo DateTimeItem stores information in a format that clashes with JSON schema's, and it has no automatic conversion. Figure out a better solution for both this and \Drupal\experience_builder\PropExpressions\StructuredData\Evaluator::evaluate().
        $default_value = ($is_image || $is_datetime)
          ? $prop_info['examples'][0]
          : $component->getDefaultStaticPropSource($component_prop->propName)?->evaluate(NULL);
        if ($default_value !== NULL) {
          $keyed_choices[$component_prop->propName]['default_values'] = $default_value;
          $default_props_for_default_markup[$component_prop->propName] = $default_value;
        }
        if ($storable_prop_shape->fieldStorageSettings !== NULL) {
          $keyed_choices[$component_prop->propName]['sourceTypeSettings']['storage'] = $storable_prop_shape->fieldStorageSettings;
        }
        if ($storable_prop_shape->fieldInstanceSettings !== NULL) {
          $keyed_choices[$component_prop->propName]['sourceTypeSettings']['instance'] = $storable_prop_shape->fieldInstanceSettings;
        }
        $keyed_choices[$component_prop->propName]['jsonSchema'] = $prop_shape->resolvedSchema;
      }
      $assets = AttachedAssets::createFromRenderArray([
        '#attached' => [
          // @see \Drupal\Core\Plugin\Component::getLibraryName()
          'library' => ['core/components.' . str_replace(':', '--', $component_plugin->getPluginId())],
        ],
      ]);
      $default_markup = (string) $this->prepareRenderArray($component_plugin->getPluginId(), $default_props_for_default_markup)['markup'];

      $component_list[] = [
        'id' => $component_plugin->getPluginId(),
        'name' => $component_plugin->metadata->name,
        'metadata' => $component_plugin->metadata,
        'field_data' => $keyed_choices,
        // A pre-rendered version of the component is provided so no requests
        // are needed when adding it to the layout which includes a default markup,
        // CSS files, JS files in the header and JS files in the footer.
        'default_markup' => $default_markup,
        'css' => $this->assetRenderer->renderCssAssets($assets),
        'js_header' => $this->assetRenderer->renderJsHeaderAssets($assets),
        'js_footer' => $this->assetRenderer->renderJsFooterAssets($assets),
        'dynamic_prop_source_candidates' => $dynamic_prop_source_candidates,
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
    return (new CacheableJsonResponse())
      ->addCacheableDependency((new CacheableMetadata())->addCacheTags(['config:component_list']))
      ->setData($this->getComponentsList());
  }

  public function prepareRenderArray(string $component_id, array $props_values): array {
    $build = [
      '#type' => 'component',
      '#component' => $component_id,
      '#props' => $props_values,
    ];

    $component_info = array_filter(
      $this->componentPluginManager->getAllComponents(),
      fn($component) => $component->getPluginId() === $component_id,
    );
    assert(!empty($component_info));
    $component = array_values($component_info)[0];
    $metadata = $component->metadata;

    $rendered_component = $this->renderer->render($build);
    return [
      'id' => $component_id,
      'markup' => $rendered_component,
      'props' => $props_values,
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
      // If the model is empty return an empty object to ensure it is encoded as
      // an object and not empty array.
      'model' => empty($model) ? new \stdClass() : $model,
    ]);
  }

}
