<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
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
 *        "add" = "Drupal\experience_builder\Form\ComponentEditForm",
 *        "edit" = "Drupal\experience_builder\Form\ComponentEditForm",
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
 *      "edit-form" = "/admin/structure/component/edit/{component}",
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

    $plugin_manager = \Drupal::service(ComponentPluginManager::class);
    assert($plugin_manager instanceof ComponentPluginManager);

    $component = $plugin_manager->find($this->getComponentMachineName());
    assert($component instanceof ComponentPlugin);

    $provider = $component->getBaseId();
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

    // When no field type is specified, fall back to the default.
    // @todo Remove this fallback logic in https://www.drupal.org/project/experience_builder/issues/3463999, and rely solely on what is defined in the Component config entity. This non-ideal issue merging order was chosen to allow https://www.drupal.org/project/experience_builder/issues/3463583 to be worked on sooner.
    if ($this->defaults['props'][$prop_name]['field_type'] === NULL) {
      $component_prop_expression = new ComponentPropExpression($component->getPluginId(), $prop_name);
      $prop_shape = PropShape::getComponentProps($component)[(string) $component_prop_expression];
      $storable_prop_shape = $prop_shape->findFieldTypeStorage();
      if ($storable_prop_shape === NULL) {
        return NULL;
      }
      return $storable_prop_shape->toStaticPropSource();
    }

    $sdc_prop_source = [
      'sourceType' => 'static:field_item:' . $this->defaults['props'][$prop_name]['field_type'],
      'value' => $this->defaults['props'][$prop_name]['default_value'],
      'expression' => $this->defaults['props'][$prop_name]['expression'],
    ];
    if (array_key_exists('field_storage_settings', $this->defaults['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings'] = $this->defaults['props'][$prop_name]['field_storage_settings'];
    }

    return StaticPropSource::parse($sdc_prop_source);
  }

}
