<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\ExperienceBuilder\ComponentSource;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Entity\TypedData\EntityDataDefinition;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\Component\ComponentValidator;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\AssetRenderer;
use Drupal\experience_builder\Attribute\ComponentSource;
use Drupal\experience_builder\ComponentSource\ComponentSourceBase;
use Drupal\experience_builder\ComponentSource\ComponentSourceWithSlotsInterface;
use Drupal\experience_builder\Entity\Component as ComponentEntity;
use Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\ShapeMatcher\FieldForComponentSuggester;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a component source based on single-directory components.
 *
 * @phpstan-import-type ComponentClientSideTypeSdc from \Drupal\experience_builder\Controller\ApiComponentsController
 */
#[ComponentSource(
  id: self::SOURCE_PLUGIN_ID,
  label: new TranslatableMarkup('Single-Directory Components')
)]
final class SingleDirectoryComponent extends ComponentSourceBase implements ComponentSourceWithSlotsInterface, ContainerFactoryPluginInterface {

  public const SOURCE_PLUGIN_ID = 'sdc';

  /**
   * Constructs a new SingleDirectoryComponent.
   *
   * @param array $configuration
   *   Configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentPluginManager
   *   Component manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   Theme handler.
   * @param \Drupal\Core\Theme\Component\ComponentValidator $componentValidator
   *   Component validator.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ComponentValidator $componentValidator,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly WidgetPluginManager $fieldWidgetPluginManager,
    private readonly AssetRenderer $assetRenderer,
    private readonly FieldForComponentSuggester $fieldForComponentSuggester,
    private readonly RendererInterface $renderer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ComponentPluginManager::class),
      $container->get(ModuleHandlerInterface::class),
      $container->get(ThemeHandlerInterface::class),
      $container->get(ComponentValidator::class),
      $container->get(FieldTypePluginManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get(AssetRenderer::class),
      $container->get(FieldForComponentSuggester::class),
      $container->get(RendererInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return parent::defaultConfiguration() + [
      'plugin_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentPluginDefinition(): array {
    return $this->componentPluginManager->getDefinition($this->configuration['plugin_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentPlugin(): Component {
    // @todo this should probably use DefaultSingleLazyPluginCollection
    return $this->componentPluginManager->find($this->configuration['plugin_id']);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $component = $this->getComponentPlugin();
    $provider = $component->getBaseId();
    if ($this->moduleHandler->moduleExists($provider)) {
      return ['module' => [$provider]];
    }
    if ($this->themeHandler->themeExists($provider)) {
      return ['theme' => [$provider]];
    }
    return [];
  }

  public function getDependencies(array $settings): array {
    $dependencies = $this->calculateDependencies();

    assert(isset($settings['props']));
    assert(is_array($settings['props']));
    foreach ($settings['props'] ?? [] as ['field_type' => $field_type, 'field_widget' => $field_widget]) {
      // TRICKY: `field_type` (and `field_widget`) may not be set if no field
      // types match this SDC prop shape.
      if ($field_type === NULL) {
        continue;
      }
      $field_type_definition = $this->fieldTypePluginManager->getDefinition($field_type);
      $dependencies['module'][] = $field_type_definition['provider'];
      $field_widget_definition = $this->fieldWidgetPluginManager->getDefinition($field_widget);
      $dependencies['module'][] = $field_widget_definition['provider'];
    }

    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDescription(): TranslatableMarkup {
    try {
      $component = $this->getComponentPlugin();
      return new TranslatableMarkup('Single-directory component: %name', [
        '%name' => $component->metadata->name ?? $component->getPluginId(),
      ]);
    }
    catch (\Exception) {
      return new TranslatableMarkup('Invalid/broken Single-directory component');
    }
  }

  /**
   * Return the schema for the component.
   *
   * @return array
   *   The schema.
   */
  public function getSchema(): array {
    return $this->getComponentPlugin()->metadata->schema ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function renderComponent(array $inputs): array {
    return [
      '#type' => 'component',
      '#cache' => [
        'tags' => [
          // @see \Drupal\Core\Config\Entity\ConfigEntityBase::getCacheTagsToInvalidate()
          'config:experience_builder.component.' . self::convertMachineNameToId($this->configuration['plugin_id']),
        ],
      ],
      '#component' => $this->configuration['plugin_id'],
      '#props' => $inputs['props'] ?? [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSlotDefinitions(): array {
    return $this->getComponentPlugin()->metadata->slots;
  }

  /**
   * {@inheritdoc}
   */
  public function setSlots(array &$build, array $slots): void {
    $build['#slots'] = $slots;
  }

  /**
   * {@inheritdoc}
   */
  public function hydrateComponent(string $uuid, ComponentTreeItem $item): array {
    $hydrated['props'] = $item->resolveComponentProps($uuid);

    if ($slots = $this->getSlotDefinitions()) {
      // Use the first example defined in SDC metadata, if it exists. Otherwise,
      // fall back to `"#plain_text => ''`, which is accepted by SDC's rendering
      // logic but still results in an empty slot.
      // @see https://www.drupal.org/node/3391702
      // @see \Drupal\Core\Render\Element\ComponentElement::generateComponentTemplate()
      // @see \Drupal\experience_builder\Controller\ApiPreviewController::wrapComponentsForPreview()
      $hydrated['slots'] = array_map(fn($slot) => $slot['examples'][0] ?? '', $slots);
    }

    return $hydrated;
  }

  /**
   * {@inheritdoc}
   */
  public function validateComponentProperties(array $propertyValues = []): void {
    $this->componentValidator->validateProps($propertyValues, $this->getComponentPlugin());
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-return ComponentClientSideTypeSdc
   */
  public function getClientSideInfo(ComponentEntity $component, ?bool $cache_tags = TRUE): array {
    $component_plugin = $this->getComponentPlugin();
    assert($component_plugin instanceof Component);
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

    // @todo return this as a single build array and let the controller render and extract assets? Decide in https://www.drupal.org/project/experience_builder/issues/3484678
    $build = $this->renderComponent(['props' => $default_props_for_default_markup]);
    if (!$cache_tags) {
      unset($build['#cache']);
    }
    $assets = AttachedAssets::createFromRenderArray([
      '#attached' => [
        // @see \Drupal\Core\Plugin\Component::getLibraryName()
        'library' => ['core/components.' . str_replace(':', '--', $component_plugin->getPluginId())],
      ],
    ]);

    return [
      'id' => $component->id(),
      'name' => $component_plugin->metadata->name,
      // A pre-rendered version of the component is provided so no requests
      // are needed when adding it to the layout which includes a default markup,
      // CSS files, JS files in the header and JS files in the footer.
      'default_markup' => $this->renderer->render($build),
      'css' => $this->assetRenderer->renderCssAssets($assets),
      'js_header' => $this->assetRenderer->renderJsHeaderAssets($assets),
      'js_footer' => $this->assetRenderer->renderJsFooterAssets($assets),
      // Additional data only needed for SDCs.
      // @todo UI does not use any other metadata - should `slots` move to top level?
      'metadata' => ['slots' => $this->getSlotDefinitions()],
      'field_data' => $keyed_choices,
      'dynamic_prop_source_candidates' => $dynamic_prop_source_candidates,
    ];
  }

  /**
   * Converts an SDC plugin machine name into a config entity ID.
   *
   * The naming convention for SDC plugin components is [module/theme]:[component machine name]. Colon is invalid config entity name, so we replace it with '.'.
   * @see https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/api-for-single-directory-components
   *
   * @param string $machine_name
   *   The SDC plugin.
   *
   * @return string
   *   The config entity ID.
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function convertMachineNameToId(string $machine_name): string {
    assert(str_contains($machine_name, ':'));
    return 'sdc.' . str_replace(':', '.', $machine_name);
  }

  /**
   * Create a Component config entity for a Single Directory Component plugin.
   *
   * @param \Drupal\Core\Plugin\Component $component_plugin
   *   The SDC plugin.
   *
   * @return \Drupal\experience_builder\Entity\Component
   *   The component config entity.
   */
  public static function createConfigEntity(ComponentPlugin $component_plugin): ComponentEntity {
    assert(is_array($component_plugin->metadata->schema));
    $props = self::getPropsForComponentPlugin($component_plugin);
    assert(is_array($component_plugin->getPluginDefinition()));
    $status = !(isset($component_plugin->metadata->status) && $component_plugin->metadata->status === 'obsolete');
    return ComponentEntity::create([
      'id' => self::convertMachineNameToId($component_plugin->getPluginId()),
      'label' => $component_plugin->getPluginDefinition()['name'] ?? $component_plugin->getPluginId(),
      'source' => self::SOURCE_PLUGIN_ID,
      'settings' => [
        'plugin_id' => $component_plugin->getPluginId(),
        'props' => $props,
      ],
      'status' => $status,
    ]);
  }

  /**
   * Update the Component config entity for a Single Directory Component plugin.
   *
   * @param \Drupal\Core\Plugin\Component $component_plugin
   *   The SDC plugin.
   *
   * @return \Drupal\experience_builder\Entity\Component
   *   The component config entity.
   */
  public static function updateConfigEntity(ComponentPlugin $component_plugin): ComponentEntity {
    $component = ComponentEntity::load(self::convertMachineNameToId($component_plugin->getPluginId()));
    assert($component instanceof ComponentEntity);
    assert(is_array($component_plugin->metadata->schema));

    $settings = $component->get('settings');
    $settings['props'] = self::getPropsForComponentPlugin($component_plugin);
    $component->set('settings', $settings);

    return $component;
  }

  /**
   * Build the prop settings for an SDC component.
   *
   * @param \Drupal\Core\Plugin\Component $component_plugin
   *   The SDC component.
   *
   * @return array
   *   The prop settings.
   */
  public static function getPropsForComponentPlugin(ComponentPlugin $component_plugin): array {
    $props = [];
    foreach (PropShape::getComponentProps($component_plugin) as $cpe_string => $prop_shape) {
      $cpe = ComponentPropExpression::fromString($cpe_string);

      assert(is_array($component_plugin->metadata->schema));
      // @see https://json-schema.org/understanding-json-schema/reference/object#required
      // @see https://json-schema.org/learn/getting-started-step-by-step#required
      $is_required = in_array($cpe->propName, $component_plugin->metadata->schema['required'] ?? [], TRUE);

      $skip_prop = FALSE;
      $storable_prop_shape = $prop_shape->getStorage();
      if (is_null($storable_prop_shape)) {
        continue;
      }

      if ($storable_prop_shape->fieldTypeProp instanceof FieldTypeObjectPropsExpression) {
        // @todo Add support for default images: /components/image/image.component.yml.
        if ($storable_prop_shape->fieldTypeProp->fieldType === 'entity_reference') {
          $skip_prop = TRUE;
        }
        else {
          foreach ($storable_prop_shape->fieldTypeProp->objectPropsToFieldTypeProps as $field_type_prop) {
            if ($field_type_prop instanceof ReferenceFieldTypePropExpression) {
              $skip_prop = TRUE;
            }
          }
        }
      }
      $static_prop_source = $storable_prop_shape->toStaticPropSource();

      // @see `type: experience_builder.component.*`
      assert(array_key_exists('properties', $component_plugin->metadata->schema));
      $props[$cpe->propName] = [
        'field_type' => $storable_prop_shape->fieldTypeProp->fieldType,
        'field_widget' => $storable_prop_shape->fieldWidget,
        'expression' => (string) $storable_prop_shape->fieldTypeProp,
        // TRICKY: need to transform to the array structure that depends on the
        // field type.
        // @see `type: field.storage_settings.*`
        'default_value' => $skip_prop ? [] : $static_prop_source->withValue(
          $is_required
            // Example guaranteed to exist if a required prop.
            ? $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'][0]
            // Example may exist if an optional prop.
            : (
          array_key_exists('examples', $component_plugin->metadata->schema['properties'][$cpe->propName]) && array_key_exists(0, $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'])
            ? $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'][0]
            : NULL
          )
        )->fieldItem->getValue(),
        'field_storage_settings' => $storable_prop_shape->fieldStorageSettings ?? [],
        'field_instance_settings' => $storable_prop_shape->fieldInstanceSettings ?? [],
      ];
    }

    return $props;
  }

}
