<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

final class JsonSchemaPropsComponentInstanceUpdater implements ComponentInstanceUpdaterInterface {

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
   * - Increasing `maxItems` (cardinality) for `type: array` props
   *
   * Unsafe changes considered acceptable (⚠️) include:
   * - Decreasing cardinality of `type: array` props (acceptable data loss:
   *   excess stored values are dropped)
   *
   * Unsafe changes (that prevent auto-update) include:
   * - Changing prop shapes
   *
   * @see \Drupal\Tests\canvas\Kernel\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentInstanceUpdaterTest::providerUpdate
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
    $prop_field_definition_to_storable_prop_shape = static function (array $prop_field_definition): StorablePropShape {
      $field_type_prop = StructuredDataPropExpression::fromString($prop_field_definition['expression']);
      \assert($field_type_prop instanceof FieldTypeBasedPropExpressionInterface);

      // Shape details are irrelevant for update safety checks, but the
      // constructor validates that cardinality aligns with array/non-array.
      $is_single_value = ($prop_field_definition['cardinality'] ?? StorablePropShape::DEFAULT_CARDINALITY) === 1;
      $irrelevant_prop_shape = $is_single_value
        ? new PropShape(['type' => 'object'])
        : new PropShape(['type' => 'array', 'items' => ['type' => 'object']]);

      // Normalize to unlimited so cardinality changes don't block the update.
      // - Increasing cardinality is allowed: the data fits in the new storage.
      // - Decreasing cardinality is allowed: the data loss is accepted on
      // update.
      $comparison_cardinality = $is_single_value ? NULL : FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED;

      return new StorablePropShape(
        shape: $irrelevant_prop_shape,
        fieldTypeProp: $field_type_prop,
        fieldWidget: 'irrelevant',
        cardinality: $comparison_cardinality,
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

    // Remove inputs for props that don't exist in the active version. This
    // covers both props removed between versions and garbage inputs for props
    // that never existed in any version.
    // @see https://www.drupal.org/project/canvas/issues/3578865
    $unknown_inputs = \array_diff_key($inputs, $to_props);
    if (count($unknown_inputs) > 0) {
      $inputs = \array_intersect_key($inputs, $to_props);
      $needs_input_update = TRUE;
    }

    // Truncate array inputs exceeding the new finite cardinality. This handles
    // the case where maxItems was tightened after component instances were
    // already created.
    foreach ($to_props as $prop_name => $def) {
      // @see \Drupal\canvas\PropSource\StaticPropSource::getCardinality()
      $new_cardinality = $def['cardinality'] ?? StorablePropShape::DEFAULT_CARDINALITY;
      // When the new cardinality is unlimited, no change is necessary because
      // any number of stored values is valid.
      if ($new_cardinality === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
        continue;
      }
      // Skip if prop is absent, not an array, or already within the new limit.
      if (
        !isset($inputs[$prop_name]) ||
        !\is_array($inputs[$prop_name]) ||
        \count($inputs[$prop_name]) <= $new_cardinality
      ) {
        continue;
      }

      $inputs[$prop_name] = \array_slice($inputs[$prop_name], 0, $new_cardinality);
      $needs_input_update = TRUE;
    }

    // Validate the projected inputs against the target version's source to
    // detect existing inputs that are invalid for the new version.
    $target_source = $component->loadVersion($to_version)->getComponentSource();
    $component_uuid = $component_instance->getUuid();
    $violations = $target_source->validateComponentInput(
      inputValues: $inputs,
      component_instance_uuid: $component_uuid,
      entity: NULL,
    );
    // Collect prop names that have violations in the target version.
    $invalid_prop_names = [];
    foreach ($violations as $violation) {
      $path = $violation->getPropertyPath();
      $prefix = "inputs.$component_uuid.";
      if (str_starts_with($path, $prefix)) {
        $invalid_prop_names[substr($path, \strlen($prefix))] = TRUE;
      }
    }

    // Add default prop values for required props that are new, now required,
    // or invalid in the active version.
    foreach ($to_props as $prop_name => $def) {
      if ($def['required'] !== TRUE ||
          // If we happened to have a "garbage" value from a previous version,
          // we would need to preserve it if now became valid.
          (\array_key_exists($prop_name, $inputs) && !isset($invalid_prop_names[$prop_name]))) {
        continue;
      }

      if (isset($from_props[$prop_name])) {
        // This prop already existed, so we only need to add a default value if
        // it was previously optional (and is now required) and no value exists
        // for it yet — or if the existing input is invalid for the new version.
        if (($from_props[$prop_name]['required'] === TRUE || isset($inputs[$prop_name])) && !isset($invalid_prop_names[$prop_name])) {
          continue;
        }
      }

      $default_explicit_input = $target_source->getDefaultExplicitInput();
      // Required props must have an example value and hence a value in this
      // component's default explicit input.
      // @see \Drupal\canvas\ComponentMetadataRequirementsChecker
      \assert(\array_key_exists('value', $default_explicit_input[$prop_name]) && $default_explicit_input[$prop_name]['value'] !== NULL);
      $inputs[$prop_name] = $default_explicit_input[$prop_name]['value'];
      $needs_input_update = TRUE;
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
        return !($slot !== NULL && $item->getParentUuid() === $component_uuid && \in_array($slot, $removed_slot_names, TRUE));
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
