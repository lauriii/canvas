<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;

final class GeneratedFieldExplicitInputUxComponentInstanceUpdater implements ComponentInstanceUpdaterInterface {

  /**
   * {@inheritdoc}
   */
  public function isUpdateNeeded(ComponentTreeItem $component_instance): bool {
    $component = $component_instance->getComponent();
    // If the Component config entity disappeared, we cannot update.
    if ($component === NULL) {
      return FALSE;
    }
    // If we are at the latest version already: no-op.
    if ($component_instance->getComponentVersion() === $component->getActiveVersion()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * This method determines if updating the component instance from its current
   * version to the active version involves only backward-compatible changes
   * (safe changes). Safe changes include:
   * - Adding optional props
   * - Adding or removing slots
   * - Removing props (required or optional)
   * - Adding required props
   * - Changing props from optional to required
   * - Adding slots
   * - Changing props from required to optional
   * - Changing a prop matched prop shape field widget (but only the widget!)
   * - Changing default values in prop_field_definitions
   * - Changing slot examples
   *
   * Unsafe changes (that prevent auto-update) include:
   * - Changing prop shapes
   *
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentInstanceUpdaterTest::providerUpdate
   */
  public function canUpdate(ComponentTreeItem $component_instance): bool {
    $component = $component_instance->getComponent();
    // If the Component config entity disappeared, we cannot update.
    if ($component === NULL) {
      return FALSE;
    }
    if (!$this->isUpdateNeeded($component_instance)) {
      return FALSE;
    }
    $from_version = $component->getLoadedVersion();
    $to_version = $component->getActiveVersion();

    // Props that are still present, need to allow the same field data to be
    // stored in the active version of the Component. If not
    // (If only the field widget or expression changes, it's SAFE to update.)
    $irrelevant_prop_shape = new PropShape(['type' => 'string']);
    $prop_field_definition_to_storable_prop_shape = static function (array $prop_field_definition) use ($irrelevant_prop_shape): StorablePropShape {
      $field_type_prop = StructuredDataPropExpression::fromString($prop_field_definition['expression']);
      \assert($field_type_prop instanceof FieldTypeBasedPropExpressionInterface);
      return new StorablePropShape(
        shape: $irrelevant_prop_shape,
        fieldTypeProp: $field_type_prop,
        fieldWidget: 'irrelevant',
        cardinality: $prop_field_definition['cardinality'] ?? NULL,
        fieldStorageSettings: $prop_field_definition['field_storage_settings'] ?? NULL,
        fieldInstanceSettings: $prop_field_definition['field_instance_settings'] ?? NULL,
      );
    };
    [$from_props, $to_props] = self::getPropDefinitions($component, $from_version, $to_version);
    $from_props = \array_map($prop_field_definition_to_storable_prop_shape, $from_props);
    $to_props = \array_map($prop_field_definition_to_storable_prop_shape, $to_props);
    $common_props_names = \array_keys(\array_intersect_key($to_props, $from_props));
    $common_props_names_with_changed_definition = \array_any(
      $common_props_names,
      fn (string $prop_name): bool => !$from_props[$prop_name]->fieldDataFitsIn($to_props[$prop_name]),
    );
    if ($common_props_names_with_changed_definition) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function update(ComponentTreeItem $component_instance): ComponentInstanceUpdateAttemptResult {
    if (!$this->isUpdateNeeded($component_instance)) {
      return ComponentInstanceUpdateAttemptResult::NotNeeded;
    }
    if (!$this->canUpdate($component_instance)) {
      return ComponentInstanceUpdateAttemptResult::NotAllowed;
    }
    $component = $component_instance->getComponent();
    \assert($component instanceof ComponentInterface);
    $from_version = $component_instance->getComponentVersion();
    $to_version = $component->getActiveVersion();
    \assert($from_version !== $to_version);

    [$from_props, $to_props] = self::getPropDefinitions($component, $from_version, $to_version);

    $inputs = $component_instance->getInputs() ?? [];
    $needs_input_update = FALSE;

    // Remove prop values for props that no longer exist in the active version.
    $removed_prop_names = \array_diff_key($from_props, $to_props);
    if (count($removed_prop_names) > 0) {
      $inputs = \array_diff_key($inputs, $removed_prop_names);
      $needs_input_update = TRUE;
    }

    // Add default prop values for required props that are new or now required
    // in the active version, if they weren't already set.
    foreach ($to_props as $prop_name => $def) {
      if ($def['required'] !== TRUE) {
        continue;
      }
      $default_explicit_input = $component->loadVersion($to_version)->getComponentSource()->getDefaultExplicitInput();
      // Required props must have an example value and hence a value in this
      // component's default explicit input.
      // @see \Drupal\canvas\ComponentMetadataRequirementsChecker
      \assert(\array_key_exists('value', $default_explicit_input[$prop_name]) && $default_explicit_input[$prop_name]['value'] !== NULL);
      $value = $default_explicit_input[$prop_name]['value'];
      // New required prop: always set to default_value.
      if (!isset($from_props[$prop_name])) {
        // The prop didn't exist before, so there cannot be an existing input.
        \assert(!isset($inputs[$prop_name]));
        $inputs[$prop_name] = $value;
        $needs_input_update = TRUE;
      }
      // Optional→required prop: only set default_value if no input exists
      // (respect user-provided values for props that already existed).
      elseif ($from_props[$prop_name]['required'] !== TRUE && !isset($inputs[$prop_name])) {
        $inputs[$prop_name] = $value;
        $needs_input_update = TRUE;
      }
    }

    if ($needs_input_update) {
      $component_instance->setInput($inputs);
    }

    $from_slots = $component->getSlotDefinitions($from_version);
    $to_slots = $component->getSlotDefinitions($to_version);
    $removed_slot_names = \array_keys(\array_diff_key($from_slots, $to_slots));
    if (count($removed_slot_names) > 0) {
      $component_tree_list = $component_instance->getParent();
      \assert($component_tree_list instanceof ComponentTreeItemList);
      $component_uuid = $component_instance->getUuid();
      $component_tree_list->filter(static function (ComponentTreeItem $item) use ($component_uuid, $removed_slot_names): bool {
        $slot = $item->getSlot();
        return !($slot !== NULL && $item->getParentUuid() === $component_uuid && in_array($slot, $removed_slot_names, TRUE));
      });
    }

    // Update the version to the active version.
    $component_instance->set(
      'component_version',
      $to_version
    );
    return ComponentInstanceUpdateAttemptResult::Latest;
  }

  /**
   * Gets prop definitions from two versions of a Component config entity.
   *
   * @param \Drupal\canvas\Entity\ComponentInterface $component
   *   The component.
   * @param string $from_version
   *   The version of the component to compare from.
   * @param string $to_version
   *   The version of the component to compare.
   *
   * @return array
   *   An array containing the prop field definitions.
   */
  private static function getPropDefinitions(ComponentInterface $component, string $from_version, string $to_version): array {
    $from_settings = $component->getSettings($from_version);
    $to_settings = $component->getSettings($to_version);
    return [$from_settings['prop_field_definitions'], $to_settings['prop_field_definitions']];
  }

}
