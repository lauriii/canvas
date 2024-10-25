<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Controller;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\experience_builder\AssetRenderer;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides the client side with all information needed of all XB Components.
 *
 * @see ui/src/types/Component.ts
 * @phpstan-import-type ComponentConfigEntityId from \Drupal\experience_builder\Entity\Component
 * @phpstan-type ComponentClientSideTypeAny array{'id': string, 'name': string, 'default_markup': string|\Stringable, 'css': string|\Stringable, 'js_header': string|\Stringable, 'js_footer': string|\Stringable}
 * @phpstan-type ComponentClientSideTypeSdc array{'id': string, 'name': string, 'default_markup': string|\Stringable, 'css': string|\Stringable, 'js_header': string|\Stringable, 'js_footer': string|\Stringable, 'metadata': array<string, mixed>, 'field_data': array<string, mixed>, 'dynamic_prop_source_candidates': array<string, mixed>,}
 */
final class ApiComponentsController {

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RendererInterface $renderer,
    private readonly AssetRenderer $assetRenderer,
    protected readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    protected readonly FieldForComponentSuggester $fieldForComponentSuggester,
  ) {}

  /**
   * Provides a list of XB Components as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The components list.
   */
  public function __invoke() : JsonResponse {
    return (new CacheableJsonResponse())
      ->addCacheableDependency((new CacheableMetadata())->addCacheTags(['config:component_list']))
      ->setData($this->getComponentsList());
  }

  /**
   * Gets an array of all XB Components, prepared for XB's client side.
   *
   * @return array<ComponentConfigEntityId, ComponentClientSideTypeAny|ComponentClientSideTypeSdc>
   *   An array of XB Component config entities, with for each:
   *   - `id`: the Component config entity ID
   *   - `name`: human-readable name
   *   - `default_markup`: without providing user input, this is what
   *     Component's markup would look like — used to preview the Component
   *     prior to placing it
   *   - `css`: markup to load CSS assets associated with `default_markup`
   *   - `js_header`: markup to load header JS assets associated with
   *     `default_markup`
   *   - `js_footer`: markup to load footer JS assets associated with
   *     `default_markup`
   *
   *   And when the XB Component type is `sdc`, it also adds:
   *   - `metadata`: SDC metadata
   *   - `field_data`: the StaticPropSources to use for each SDC prop
   *   - `dynamic_prop_source_candidates`: the DynamicPropSources that match
   *      each
   */
  private function getComponentsList(): array {
    $component_info_list = [];
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

      $component_info = [
        'id' => $component->id(),
        'name' => $component_plugin->metadata->name,
        // A pre-rendered version of the component is provided so no requests
        // are needed when adding it to the layout which includes a default markup,
        // CSS files, JS files in the header and JS files in the footer.
        'default_markup' => $default_markup,
        'css' => $this->assetRenderer->renderCssAssets($assets),
        'js_header' => $this->assetRenderer->renderJsHeaderAssets($assets),
        'js_footer' => $this->assetRenderer->renderJsFooterAssets($assets),
      ];

      // @todo Make this conditional in https://www.drupal.org/project/experience_builder/issues/3475584, do something else for blocks.
      $component_info += [
        'metadata' => $component_plugin->metadata,
        'field_data' => $keyed_choices,
        'dynamic_prop_source_candidates' => $dynamic_prop_source_candidates,
      ];

      $component_info_list[] = $component_info;
    }

    // Component array is keyed by ID.
    return array_combine(array_column($component_info_list, 'id'), $component_info_list);
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

}
