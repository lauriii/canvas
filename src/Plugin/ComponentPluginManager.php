<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\Core\Theme\ComponentPluginManager as CoreComponentPluginManager;
use Drupal\Core\Theme\ExtensionType;
use Drupal\experience_builder\Entity\Component;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropShape;
use Drupal\experience_builder\StorablePropShape;

/**
 * Decorator that auto-creates/updates an Experience Builder Component entity per SDC.
 *
 * @see \Drupal\experience_builder\Entity\Component
 */
class ComponentPluginManager extends CoreComponentPluginManager {

  protected EntityTypeManagerInterface $entityTypeManager;

  public function setEntityTypeManager(EntityTypeManagerInterface $entityTypeManager): void {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  protected function setCachedDefinitions($definitions): array {
    parent::setCachedDefinitions($definitions);

    $components = $this->entityTypeManager->getStorage('component')->loadMultiple();
    foreach ($definitions as $machine_name => $plugin_definition) {
      // Update all components, even those that do not meet the requirements.
      // (Because those components may already be in use!)
      if (array_key_exists(Component::convertMachineNameToId($machine_name), $components)) {
        $component_plugin = $this->createInstance($machine_name, $plugin_definition);
        $component = Component::updateFromComponentPlugin($component_plugin);
      }
      else {
        if (!self::componentMeetsRequirements($plugin_definition)) {
          continue;
        }
        $component_plugin = $this->createInstance($machine_name, $plugin_definition);
        $component = Component::createFromComponentPlugin($component_plugin);
      }
      $component->save();
    }

    return $definitions;
  }

  public static function componentMeetsRequirements(array $plugin_definition): bool {
    if (isset($plugin_definition['status']) && $plugin_definition['status'] === 'obsolete') {
      return FALSE;
    }
    // Special case exception for 'all-props' SDC.
    // (This is used to develop support for more prop shapes.)
    if ($plugin_definition['id'] === 'sdc_test_all_props:all-props') {
      return TRUE;
    }

    if (isset($plugin_definition['props']['required'])) {
      foreach ($plugin_definition['props']['required'] as $prop) {
        // Every required prop must have >=1 example.
        if (empty($plugin_definition['props']['properties'][$prop]['examples'])) {
          return FALSE;
        }
      }
    }
    foreach ($plugin_definition['props']['properties'] as $prop_name => $prop) {
      if ($prop_name === 'attributes') {
        continue;
      }
      // Every prop must have a title.
      if (!isset($prop['title'])) {
        return FALSE;
      }
      // Every prop must have a StorablePropShape.
      if (!self::propHasStorablePropShape($prop_name, $plugin_definition)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  protected static function propHasStorablePropShape(string $prop_name, array $plugin_definition): bool {
    $metadata = self::createComponentMetadataFromPluginDefinition($plugin_definition);
    $component_prop_expression = new ComponentPropExpression($plugin_definition['id'], $prop_name);
    $prop_shape = PropShape::getComponentPropsForMetadata($plugin_definition['id'], $metadata)[(string) $component_prop_expression];
    $storable_prop_shape = $prop_shape->getStorage();
    return $storable_prop_shape instanceof StorablePropShape;
  }

  protected static function createComponentMetadataFromPluginDefinition(array $plugin_definition): ComponentMetadata {
    // Copied logic from ComponentPluginManager::shouldEnforceSchema() as it is set to private visibility.
    // @see \Drupal\Core\Theme\ComponentPluginManager::shouldEnforceSchemas()
    if (isset($plugin_definition['extension_type']) && $plugin_definition['extension_type'] !== ExtensionType::Theme) {
      $should_enforce_schemas = TRUE;
    }
    else {
      $should_enforce_schemas = \Drupal::service('theme_handler')
        ->getTheme($plugin_definition['provider'])
        ?->info['enforce_prop_schemas'] ?? FALSE;
    }

    $metadata = new ComponentMetadata(
      $plugin_definition,
      \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT,
      (bool) ($should_enforce_schemas)
    );

    return $metadata;
  }

}
