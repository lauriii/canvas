<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\experience_builder\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\experience_builder\PropShape;
use Drupal\experience_builder\PropSource\StaticPropSource;

/**
 * @todo Update these docs in https://drupal.org/i/3454519 to reflect changes.
 *
 * A config entity that exposes SDC components to the Experience Builder UI.
 * 1. There can be only one Component entity per component plugin.
 *
 *
 * @ConfigEntityType(
 *    id = "component",
 *    label = @Translation("Component"),
 *    label_singular = @Translation("component"),
 *    label_plural = @Translation("components"),
 *    label_collection = @Translation("Components"),
 *    admin_permission = "administer components",
 *    handlers = {
 *      "list_builder" = "Drupal\experience_builder\Form\ComponentListBuilder",
 *      "form" = {
 *        "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *      },
 *      "route_provider" = {
 *        "html" = "\Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *      }
 *    },
 *    entity_keys = {
 *      "id" = "component",
 *      "label" = "label",
 *    },
 *    links = {
 *      "delete-form" = "/admin/structure/component/delete/{component}",
 *      "collection" = "/admin/structure/component",
 *    },
 *    config_export = {
 *      "label",
 *      "component",
 *      "defaults",
 *    }
 *  )
 */
final class Component extends ConfigEntityBase {

  /**
   * The human-readable label of the Experience Builder component.
   */
  protected ?string $label;

  /**
   * Component entity ID, based on component plugin machine name.
   *
   * Component plugin machine names are required to contain `:`, which is an
   * invalid character for a config entity ID, hence this transformation.
   *
   * @see self::convertMachineNameToId()
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  protected string $component;

  /**
   * @var array{"props": array<string, array{"field_type": string, "field_widget": string, "default_value": mixed, "expression": string}>}
   */
  protected ?array $defaults;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->get('component');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $provider = explode(':', $this->getComponentMachineName())[0];

    if ($this->moduleHandler()->moduleExists($provider)) {
      $this->addDependency('module', $provider);
    }
    elseif ($this->themeHandler()->themeExists($provider)) {
      $this->addDependency('theme', $provider);
    }

    $field_type_plugin_manager = \Drupal::service(FieldTypePluginManagerInterface::class);
    assert($field_type_plugin_manager instanceof FieldTypePluginManagerInterface);
    $field_widget_plugin_manager = \Drupal::service('plugin.manager.field.widget');
    assert($field_widget_plugin_manager instanceof WidgetPluginManager);
    assert(is_array($this->defaults));
    foreach ($this->defaults['props'] ?? [] as ['field_type' => $field_type, 'field_widget' => $field_widget]) {
      // TRICKY: `field_type` (and `field_widget`) may not be set if no field
      // types match this SDC prop shape.
      if ($field_type === NULL) {
        continue;
      }
      $field_type_definition = $field_type_plugin_manager->getDefinition($field_type);
      $this->addDependency('module', $field_type_definition['provider']);
      $field_widget_definition = $field_widget_plugin_manager->getDefinition($field_widget);
      $this->addDependency('module', $field_widget_definition['provider']);
    }

    return $this;
  }

  /**
   * Gets the component plugin machine name.
   *
   * @return string
   *   The component plugin machine name.
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public function getComponentMachineName(): string {
    return self::convertIdToMachineName($this->get('component'));
  }

  /**
   * Loads a component entity by its component plugin machine name.
   *
   * This works because there can only ever be one Component entity per component plugin.
   *
   * @param string $component_machine_name
   *   The component plugin machine name.
   *
   * @return \Drupal\experience_builder\Entity\Component|null
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function loadByComponentMachineName(string $component_machine_name) {
    return parent::load(self::convertMachineNameToId($component_machine_name));
  }

  /**
   * Converts a config ID to plugin machine name.
   *
   * The naming convention for SDC plugin components is [module/theme]:[component machine name], so we change '+' back to ':'.
   * @see https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/api-for-single-directory-components
   * @see \Drupal\Core\Plugin\Discovery\DirectoryWithMetadataDiscovery::getDirectoryIterator()
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function convertIdToMachineName(string $id): string {
    return str_replace('+', ':', $id);
  }

  /**
   * Converts a plugin machine name into a plugin ID.
   *
   * The naming convention for SDC plugin components is [module/theme]:[component machine name]. Colon is invalid config entity name, so we replace it with '+'.
   * @see https://www.drupal.org/docs/develop/theming-drupal/using-single-directory-components/api-for-single-directory-components
   *
   * @param string $machine_name
   *
   * @return string
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public static function convertMachineNameToId(string $machine_name): string {
    return str_replace(':', '+', $machine_name);
  }

  public function getDefaultStaticPropSource(string $prop_name): ?StaticPropSource {
    assert(is_array($this->defaults));

    $plugin_manager = \Drupal::service(ComponentPluginManager::class);
    $component = $plugin_manager->find($this->getComponentMachineName());
    assert($component instanceof ComponentPlugin);
    if (!array_key_exists($prop_name, $component->metadata->schema['properties'] ?? [])) {
      throw new \OutOfRangeException(sprintf("'%s' is not a prop on the '%s' component.", $prop_name, $this->getComponentMachineName()));
    }

    $sdc_prop_source = [
      'sourceType' => 'static:field_item:' . $this->defaults['props'][$prop_name]['field_type'],
      'value' => $this->defaults['props'][$prop_name]['default_value'],
      'expression' => $this->defaults['props'][$prop_name]['expression'],
    ];
    if (array_key_exists('field_storage_settings', $this->defaults['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['storage'] = $this->defaults['props'][$prop_name]['field_storage_settings'];
    }
    if (array_key_exists('field_instance_settings', $this->defaults['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['instance'] = $this->defaults['props'][$prop_name]['field_instance_settings'];
    }

    return StaticPropSource::parse($sdc_prop_source);
  }

  public static function updateFromComponentPlugin(ComponentPlugin $component_plugin): self {
    $component = Component::load(Component::convertMachineNameToId($component_plugin->getPluginId()));

    assert($component instanceof Component);
    assert(is_array($component_plugin->metadata->schema));
    $defaults = self::getDefaultsForComponentPlugin($component_plugin);
    $component->set('defaults', $defaults);
    if (isset($component_plugin->metadata->status) && $component_plugin->metadata->status === 'obsolete') {
      $component->disable();
    }

    return $component;
  }

  public static function getDefaultsForComponentPlugin(ComponentPlugin $component_plugin): array {
    $defaults = ['props' => []];
    if (!isset($component_plugin->metadata->schema['required']) || !is_array($component_plugin->metadata->schema['required'])) {
      return $defaults;
    }

    foreach ($component_plugin->metadata->schema['required'] as $required_prop) {
      $skip_prop = FALSE;
      $component_prop_expression = new ComponentPropExpression($component_plugin->getPluginId(), $required_prop);
      $prop_shape = PropShape::getComponentProps($component_plugin)[(string) $component_prop_expression];
      $storable_prop_shape = $prop_shape->getStorage();
      if (is_null($storable_prop_shape)) {
        continue;
      }

      if ($storable_prop_shape->fieldTypeProp instanceof FieldTypeObjectPropsExpression) {
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
      $defaults['props'][$required_prop] = [
        'field_type' => $storable_prop_shape->fieldTypeProp->fieldType,
        'field_widget' => $storable_prop_shape->fieldWidget,
        'expression' => (string) $storable_prop_shape->fieldTypeProp,
        // TRICKY: need to transform to the array structure that is field type-specific, which includes the required non-computed field properties at minimum
        'default_value' => $skip_prop ? [] : $static_prop_source->withValue($component_plugin->metadata->schema['properties'][$required_prop]['examples'][0])->fieldItem->getValue(),
        'field_storage_settings' => $storable_prop_shape->fieldStorageSettings ?? [],
        'field_instance_settings' => $storable_prop_shape->fieldInstanceSettings ?? [],
      ];
    }

    return $defaults;
  }

  public static function createFromComponentPlugin(ComponentPlugin $component_plugin): self {
    assert(is_array($component_plugin->metadata->schema));
    $defaults = self::getDefaultsForComponentPlugin($component_plugin);
    assert(is_array($component_plugin->getPluginDefinition()));
    $status = !(isset($component_plugin->metadata->status) && $component_plugin->metadata->status === 'obsolete');
    return Component::create([
      'label' => $component_plugin->getPluginDefinition()['name'] ?? $component_plugin->getPluginId(),
      'component' => self::convertMachineNameToId($component_plugin->getPluginId()),
      'defaults' => $defaults,
      'status' => $status,
    ]);
  }

}
