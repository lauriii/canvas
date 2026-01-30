<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropShape\EphemeralPropShapeRepository;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\canvas\PropShape\StorablePropShape;
use JsonSchema\Validator;

/**
 * Defines a class for checking if component metadata meets requirements.
 *
 * @todo Move into a new \Drupal\Canvas\ComponentMetadataDerivers namespace, alongside ComponentPropExpression
 */
final class ComponentMetadataRequirementsChecker {

  /**
   * Checks the given component meets requirements.
   *
   * @param string $component_id
   *   Component ID.
   * @param \Drupal\Core\Theme\Component\ComponentMetadata $metadata
   *   Component metadata.
   * @param string[] $required_props
   *   Array of required prop names.
   * @param array<string, string> $forbidden_key_characters
   *   Array of forbidden key characters as keys and replacements as values.
   *   For example, component metadata stored as Configuration entities does not
   *   allow dots.
   *
   * @throws \Drupal\canvas\ComponentDoesNotMeetRequirementsException
   *   When the component does not meet requirements.
   */
  public static function check(string $component_id, ComponentMetadata $metadata, array $required_props, array $forbidden_key_characters): void {
    $messages = [];

    if ($metadata->group == 'Elements') {
      $messages[] = 'Component uses the reserved "Elements" category';
    }

    // Every slot must have a title.
    foreach ($metadata->slots as $slot_name => $slot_definition) {
      if (!array_key_exists('title', $slot_definition)) {
        $messages[] = \sprintf('Slot "%s" must have title', $slot_name);
      }
    }

    // Check fundamentals.
    $validator = new Validator();
    foreach ($metadata->schema['properties'] ?? [] as $prop_name => $prop) {
      if (in_array(Attribute::class, $prop['type'], TRUE)) {
        continue;
      }

      // Enums must not have empty values.
      if (array_key_exists('enum', $prop) && in_array('', $prop['enum'], TRUE)) {
        $messages[] = \sprintf('Prop "%s" has an empty enum value.', $prop_name);
        continue;
      }

      // Required props must have examples.
      if (in_array($prop_name, $required_props, TRUE) && !isset($prop['examples'][0])) {
        $messages[] = \sprintf('Prop "%s" is required, but does not have example value', $prop_name);
      }

      // JSON Schema does not require that examples must be valid, but we do
      // require the first one to be, as we use it as the default value for
      // the prop.
      if (isset($prop['examples'][0])) {
        $example = $prop['examples'][0];
        if (is_array($example)) {
          $example = (object) $example;
        }
        $validator->reset();
        $validator->validate($example, $prop);
        if (!$validator->isValid()) {
          $messages[] = \sprintf('Prop "%s" has invalid example value: %s', $prop_name, implode("\n", array_map(
            static fn(array $error): string => \sprintf("[%s] %s", $error['property'], $error['message']),
            $validator->getErrors()
          )));
        }
      }

      // Validation for the additional functionality overlaid on top of the SDC
      // JSON Schema.
      // @see docs/shape-matching-into-field-types.md#3.2
      if (array_key_exists('contentMediaType', $prop) && $prop['contentMediaType'] === 'text/html' && isset($prop['x-formatting-context'])) {
        if (!in_array($prop['x-formatting-context'], ['inline', 'block'], TRUE)) {
          $messages[] = \sprintf('Invalid value "%s" for "x-formatting-context". Valid values are "inline" and "block".', $prop['x-formatting-context']);
          continue;
        }
      }

      // Every prop must have a title.
      if (!isset($prop['title'])) {
        $messages[] = \sprintf('Prop "%s" must have title', $prop_name);
      }
      if (isset($prop['enum'], $prop['meta:enum']) && !empty($forbidden_key_characters)) {
        foreach ($prop['meta:enum'] as $meta_key => $meta_value) {
          $meta_key_with_replacements = str_replace(
            array_keys($forbidden_key_characters),
            array_values($forbidden_key_characters),
            (string) $meta_key,
          );
          if ((string) $meta_key !== $meta_key_with_replacements) {
            $messages[] = \sprintf('The "meta:enum" keys for the "%s" prop enum cannot contain a dot. Offending key: "%s"', $prop_name, $meta_key);
          }
        }

        // Ensure we replace dots with underscores when checking meta:enums.
        $meta_enum_valid_keys = array_map(fn($key) => str_replace(
          array_keys($forbidden_key_characters),
          array_values($forbidden_key_characters),
          (string) $key,
        ), $prop['enum']);
        $enum_keys_diff = \array_diff($meta_enum_valid_keys, \array_keys($prop['meta:enum']));
        if (!empty($enum_keys_diff)) {
          $messages[] = \sprintf('The values for the "%s" prop enum must be defined in "meta:enum". Missing keys: "%s"', $prop_name, \implode(', ', $enum_keys_diff));
        }
      }
    }

    // Do not try computing any StorablePropShape if one or more fundamentals
    // are not right.
    if (!empty($messages)) {
      throw new ComponentDoesNotMeetRequirementsException($messages);
    }

    // Every prop must have a StorablePropShape.
    $props_for_metadata = GeneratedFieldExplicitInputUxComponentSourceBase::getComponentInputsForMetadata($component_id, $metadata);
    /** @var \Drupal\canvas\PropShape\PropShapeRepositoryInterface $prop_shape_repository */
    $prop_shape_repository = \Drupal::service(EphemeralPropShapeRepository::class);
    foreach ($props_for_metadata as $cpe => $prop_shape) {
      $storable_prop_shape = $prop_shape_repository->getStorablePropShape($prop_shape);
      if ($storable_prop_shape instanceof StorablePropShape) {
        continue;
      }
      $messages[] = \sprintf('Drupal Canvas does not know of a field type/widget to allow populating the <code>%s</code> prop, with the shape <code>%s</code>.', ComponentPropExpression::fromString($cpe)->propName, json_encode($prop_shape->schema, JSON_UNESCAPED_SLASHES));
    }
    if (!empty($messages)) {
      throw new ComponentDoesNotMeetRequirementsException($messages);
    }
  }

}
