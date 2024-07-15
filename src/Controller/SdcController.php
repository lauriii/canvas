<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\experience_builder\FieldForComponentSuggester;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\Entity\Component;

use Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated;
use Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypePropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropSource\StaticPropSource;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SdcController extends ControllerBase {

  /**
   * Constructor for the SDC controller.
   *
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentPluginManager
   * @param \Drupal\Core\Render\RendererInterface $renderer
   */
  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RendererInterface $renderer,
    private readonly FieldForComponentSuggester $fieldForComponentSuggester,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.sdc'),
      $container->get('renderer'),
      $container->get('Drupal\experience_builder\FieldForComponentSuggester'),
    );
  }

  /**
   * Gets an array of single directory components in an xb-friendly form.
   *
   * @return array<string, mixed>
   *   The array or single directory components.
   */
  private function getComponentsList(): array {
    $component_plugins = [];
    foreach (Component::loadMultiple() as $component) {
      $component_plugins[] = $this->componentPluginManager->find($component->getComponentMachineName());
    }

    $component_list = array_map(function ($component_plugin) {
      $choices = $this->fieldForComponentSuggester->suggest($component_plugin->getPluginId(), NULL);
      $keyed_choices = [];
      foreach ($choices as $component_prop_string => $data) {
        $component_prop_expression = ComponentPropExpression::fromString($component_prop_string);
        $prop_name = $component_prop_expression->propName;
        if (empty($data['types'])) {
          continue;
        }

        // The final suggested type is typically the most likely one.
        $selected_suggestion = end($data['types']);
        assert($selected_suggestion instanceof FieldTypePropExpression || $selected_suggestion instanceof FieldTypeObjectPropsExpression || $selected_suggestion instanceof ReferenceFieldTypePropExpression);

        // Default prop values are provided in the prop metadata since they will
        // be the same for every newly added SDC instance.
        $prop_info = ($component_plugin->metadata->schema['properties'] ?? [])[$prop_name];
        $default_values = isset($prop_info['examples']) ? $prop_info['examples'][0] : $this->getDefaultValueFromPropInfo($prop_info);

        // Expression and Source Type are needed for generating an SDCs prop
        // edit form in the UI app.
        $expression = (string) $selected_suggestion;
        $source_type = StaticPropSource::generate($selected_suggestion)->getSourceType();

        $keyed_choices[$component_prop_expression->propName] = [
          'expression' => $expression,
          'sourceType' => $source_type,
          'default_values' => $default_values,
          ...$data,
        ];
      }
      return [
        'id' => $component_plugin->getPluginId(),
        'name' => $component_plugin->metadata->name,
        'metadata' => $component_plugin->metadata,
        'field_data' => $keyed_choices,
        // A pre-rendered version of the component is provided so no requests
        // are needed when adding it to the layout.
        'default_markup' => (string) $this->prepareRenderArray($component_plugin->getPluginId())['markup'],
      ];
    }, $component_plugins);

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
      self::populatePropValues($build, [], $prop_name, $prop_info);
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

  public function layout(): JsonResponse {
    $first_article = Node::load(1);
    if (!$first_article || $first_article->getType() !== 'article') {
      throw new \LogicException('For now, this assumes node 1 exists and is an article!');
    }

    assert($first_article->field_xb_demo[0] instanceof ComponentTreeItem);

    $tree = $first_article->field_xb_demo[0]->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    $hydrated = $first_article->field_xb_demo[0]->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    $hydrated_json = $hydrated->getValue()->getContent();
    assert(is_string($hydrated_json));

    // @todo tree recursion/slot support — this only supports a flat list — blocked on https://www.drupal.org/project/experience_builder/issues/3455728
    $children = [];
    foreach (json_decode($tree->getValue(), TRUE)[ComponentTreeStructure::ROOT_UUID] as ['uuid' => $component_instance_uuid, 'component' => $component_type]) {
      $children[] = [
        'uuid' => $component_instance_uuid,
        // Note: the UI expects slots in this component to be defined as `type: slot`.
        'nodeType' => 'component',
        'type' => $component_type,
      ];
    }

    $model = [];
    foreach (json_decode($hydrated_json, TRUE) as $component_instance_uuid => ['props' => $resolved_prop_values]) {
      $model[$component_instance_uuid] = $resolved_prop_values;
      $component_id = $tree->getComponentId($component_instance_uuid);
      // @todo the current quick-and-dirty UI PoC unfortunately prevents any prop from being named `name`, because it expects that to convey the component name
      $component_config = Component::loadByComponentMachineName($component_id);
      assert($component_config !== NULL);
      $model[$component_instance_uuid]['name'] = $component_config->label();
    }

    // @todo This now returns a mixture of pure tree structure with hydrated props values. Re-assess.
    return new JsonResponse([
      // Maps to the `tree` property of the XB field type.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentTreeStructure
      // @todo Settle on final names and get in sync.
      'layout' => [
        'uuid' => 'root',
        'type' => 'root',
        'name' => 'root',
        'children' => $children,
      ],
      // Maps to the `props` property of the XB field type,.
      // @see \Drupal\experience_builder\Plugin\DataType\ComponentPropsValues
      // @todo Settle on final names and get in sync.
      'model' => $model,
    ]);
  }

  public function preview(Request $request): JsonResponse {
    ['layout' => $layout, 'model' => $model] = json_decode($request->getContent(), TRUE);

    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<style>
HTML;
    // @phpstan-ignore-next-line
    $html .= file_get_contents(\Drupal::service('extension.list.module')->getPath('experience_builder') . '/ui/src/mocks/styles.css');
    $html .= <<<HTML
</style>
</head>
<body>
    <div class="sortable-list" data-xb-uuid="root">
HTML;
    // @todo tree recursion — this only supports a flat list
    // @todo Refactor to use \Drupal\experience_builder\Plugin\DataType\ComponentTreeHydrated.
    foreach ($layout['children'] as ['uuid' => $uuid, 'type' => $type]) {
      $html .= sprintf('<div class="sortable-item" data-xb-uuid="%s" data-xb-type="%s">', $uuid, $type);
      // @todo the current quick-and-dirty UI PoC unfortunately prevents any prop from being named `name`, because it expects that to convey the component name — but it's not actually one of the props consumed by the SDC.
      unset($model[$uuid]['name']);
      $build = [
        '#type' => 'component',
        '#component' => $type,
        '#props' => $model[$uuid],
      ];
      // @todo support CSS + JS
      $html .= $this->renderer->renderInIsolation($build);
      $html .= '</div>';
    }
    $html .= <<<HTML
</body>
</html>
HTML;

    return new JsonResponse([
      'html' => $html,
    ]);
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
