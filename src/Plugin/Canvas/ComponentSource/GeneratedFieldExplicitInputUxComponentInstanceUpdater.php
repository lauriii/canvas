<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceUpdateAttemptResult;
use Drupal\canvas\ComponentSource\ComponentInstanceUpdaterInterface;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
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
   * - Changing props from required to optional
   * - Changing a prop matched prop shape field widget (but only the widget!)
   * - Changing default values in prop_field_definitions
   * - Changing slot examples
   *
   * Unsafe changes (that prevent auto-update) include:
   *
   * @todo We should be able to auto-update when adding a new required prop.
   *   Fix it in https://www.drupal.org/i/3568602 and move to the safe changes
   *   section.
   * - Adding a new required prop.
   * - Changing props from optional to required
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

    [$from_props, $to_props] = self::getPropDefinitions($component, $from_version, $to_version);

    // If there are new added required props, the update is UNSAFE.
    $new_props_names = \array_diff(\array_keys($to_props), \array_keys($from_props));
    $new_props = \array_intersect_key($to_props, \array_flip($new_props_names));
    $all_new_props_are_optional = \array_all($new_props, fn(array $prop_field_definition): bool => $prop_field_definition['required'] === FALSE);
    if (!$all_new_props_are_optional) {
      return FALSE;
    }

    $common_props_names = \array_keys(\array_intersect_key($to_props, $from_props));
    // For existing props, we allow changing a prop from required to optional,
    // but an optional prop cannot become required - UNSAFE
    $common_props_names_from_optional_to_required = \array_filter($common_props_names, fn(string $prop_name): bool =>
      !$from_props[$prop_name]['required'] && $to_props[$prop_name]['required']);
    if (!empty($common_props_names_from_optional_to_required)) {
      return FALSE;
    }

    // Props that are still present, need to allow the same field data to be
    // stored in the active version of the Component. If not
    // (If only the field widget or expression changes, it's SAFE to update.)
    $irrelevant_prop_shape = new PropShape(['type' => 'string']);
    $prop_field_definition_to_storable_prop_shape = static function (array $prop_field_definition) use ($irrelevant_prop_shape): StorablePropShape {
      return new StorablePropShape(
        shape: $irrelevant_prop_shape,
        // @phpstan-ignore argument.type
        fieldTypeProp: StructuredDataPropExpression::fromString($prop_field_definition['expression']),
        fieldWidget: 'irrelevant',
        cardinality: $prop_field_definition['cardinality'] ?? NULL,
        fieldStorageSettings: $prop_field_definition['field_storage_settings'] ?? NULL,
        fieldInstanceSettings: $prop_field_definition['field_instance_settings'] ?? NULL,
      );
    };
    $from_props = \array_map($prop_field_definition_to_storable_prop_shape, $from_props);
    $to_props = \array_map($prop_field_definition_to_storable_prop_shape, $to_props);
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

    // @todo handle newly added required props in https://www.drupal.org/i/3568602
    [$from_props, $to_props] = self::getPropDefinitions($component, $from_version, $to_version);
    // Remove prop values for props that no longer exist in the active version.
    $removed_prop_names = \array_diff_key($from_props, $to_props);
    if (count($removed_prop_names) > 0) {
      $component_instance->setInput(
        \array_diff_key($component_instance->getInputs() ?? [], $removed_prop_names)
      );
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
