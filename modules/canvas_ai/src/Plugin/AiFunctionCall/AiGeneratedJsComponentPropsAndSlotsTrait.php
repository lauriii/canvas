<?php

namespace Drupal\canvas_ai\Plugin\AiFunctionCall;

use Drupal\Component\Serialization\Json;

/**
 * Helpers for the props and slots the agent supplies for a JS component.
 *
 * Transforms agent-supplied slot metadata into the stored slot definition
 * shape and lists the props or slots an edit removes from a component.
 *
 * @see \Drupal\canvas\Entity\JavaScriptComponent
 * @see canvas.slot_definition in config/schema/canvas.schema.yml
 */
trait AiGeneratedJsComponentPropsAndSlotsTrait {

  /**
   * Transforms AI-supplied slot metadata into serialized slot definitions.
   *
   * Requires both a slot 'id' and 'name', with 'id' the camelCase of 'name'.
   *
   * @param string $slots
   *   The slots metadata as a JSON encoded array. An empty string means no
   *   slots were supplied and is a valid no-op.
   *
   * @return array
   *   The slots keyed by slot machine name, each value being an array with a
   *   'title' and an optional 'examples' list.
   *
   * @throws \Exception
   *   When the slots metadata is not a valid JSON array, when a slot is missing
   *   an 'id' or a 'name', or when a slot 'id' is not the camelCase of its
   *   'name'.
   */
  protected function transformSlotsMetadata(string $slots): array {
    // No slots supplied.
    if (trim($slots) === '') {
      return [];
    }

    $slots_array = Json::decode($slots);
    if (!\is_array($slots_array)) {
      throw new \Exception('The slots metadata must be a valid JSON array of slot objects.');
    }

    $transformed_slots = [];
    foreach ($slots_array as $slot) {
      $id = \is_array($slot) && isset($slot['id']) ? (string) $slot['id'] : '';
      $name = \is_array($slot) && isset($slot['name']) ? (string) $slot['name'] : '';
      if ($id === '' || $name === '') {
        $label = $id !== '' ? $id : ($name !== '' ? $name : '(unnamed)');
        throw new \Exception(\sprintf('Each slot must include both an "id" and a "name". Slot "%s" is missing one of them.', $label));
      }

      $expected_id = $this->slotNameToCamelCase($name);
      if ($id !== $expected_id) {
        throw new \Exception(\sprintf('The slot "id" must be the camelCase of the slot "name". Got id "%s" for name "%s"; expected "%s".', $id, $name, $expected_id));
      }

      $transformed = [
        'title' => $name,
      ];
      if (!empty($slot['example'])) {
        $transformed['examples'] = [$slot['example']];
      }
      $transformed_slots[$id] = $transformed;
    }
    return $transformed_slots;
  }

  /**
   * Converts a human-readable slot name to its strict camelCase identifier.
   *
   * Splits on non-alphanumeric characters, lowercases the first word, and
   * capitalizes the first letter of each later word (lowercasing the rest), so
   * "CTA Content" => "ctaContent" and "Main Content" => "mainContent".
   *
   * @param string $name
   *   The human-readable slot name.
   *
   * @return string
   *   The camelCase identifier.
   */
  protected function slotNameToCamelCase(string $name): string {
    $words = preg_split('/[^a-zA-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($words)) {
      return '';
    }
    $camel = strtolower(array_shift($words));
    foreach ($words as $word) {
      $camel .= ucfirst(strtolower($word));
    }
    return $camel;
  }

  /**
   * Builds a warning listing the props and slots an edit removes.
   *
   * @param array $existing_props
   *   The component's stored props, keyed by prop id.
   * @param array $supplied_props
   *   The props supplied in this edit, keyed by prop id.
   * @param array $existing_slots
   *   The component's stored slots, keyed by slot machine name.
   * @param array $supplied_slots
   *   The slots supplied in this edit, keyed by slot machine name.
   *
   * @return string
   *   The warning, or an empty string when nothing is removed.
   */
  protected function buildRemovedMetadataWarning(array $existing_props, array $supplied_props, array $existing_slots, array $supplied_slots): string {
    $messages = [];

    $removed_props = \array_diff_key($existing_props, $supplied_props);
    if (!empty($removed_props)) {
      $messages[] = \sprintf('These props existed on the component but were left out of this update, so they have been removed: %s. If that was not intended, run the edit again with the complete props_metadata that still includes them.', Json::encode($removed_props));
    }

    $removed_slots = \array_diff_key($existing_slots, $supplied_slots);
    if (!empty($removed_slots)) {
      $messages[] = \sprintf('These slots existed on the component but were left out of this update, so they have been removed: %s. If that was not intended, run the edit again with the complete slots_metadata that still includes them.', Json::encode($removed_slots));
    }

    return implode(' ', $messages);
  }

  /**
   * Converts stored props into the metadata shape the agent supplies.
   *
   * The array key becomes 'id', 'title' becomes 'name', and the first stored
   * example becomes 'example'. Schema keys are carried through and props listed
   * in $required_props are flagged required.
   *
   * @param array $props
   *   The component's stored props, keyed by prop id.
   * @param array $required_props
   *   The ids of the required props.
   *
   * @return array
   *   A list of prop metadata objects in the shape passed to the create and
   *   edit tools.
   */
  protected function buildPropsMetadataFromStored(array $props, array $required_props = []): array {
    $metadata = [];
    foreach ($props as $id => $prop) {
      $entry = [
        'id' => $id,
        'name' => $prop['title'] ?? $id,
        'type' => $prop['type'] ?? '',
      ];
      if (isset($prop['examples'][0])) {
        $entry['example'] = $prop['examples'][0];
      }
      foreach (['format', '$ref', 'enum', 'contentMediaType', 'x-formatting-context'] as $optional) {
        if (isset($prop[$optional])) {
          $entry[$optional] = $prop[$optional];
        }
      }
      if (\in_array($id, $required_props, TRUE)) {
        $entry['required'] = TRUE;
      }
      $metadata[] = $entry;
    }
    return $metadata;
  }

  /**
   * Converts stored slots into the metadata shape the agent supplies.
   *
   * The array key becomes 'id', 'title' becomes 'name', and the first stored
   * example becomes 'example'.
   *
   * @param array $slots
   *   The component's stored slots, keyed by slot machine name.
   *
   * @return array
   *   A list of slot metadata objects in the shape passed to the create and
   *   edit tools.
   */
  protected function buildSlotsMetadataFromStored(array $slots): array {
    $metadata = [];
    foreach ($slots as $id => $slot) {
      $entry = [
        'id' => $id,
        'name' => $slot['title'] ?? $id,
      ];
      if (isset($slot['examples'][0])) {
        $entry['example'] = $slot['examples'][0];
      }
      $metadata[] = $entry;
    }
    return $metadata;
  }

}
