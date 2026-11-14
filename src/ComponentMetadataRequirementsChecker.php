<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\JsonSchemaInterpreter\PropVisibilityCondition;
use Drupal\canvas\OptionsSource\OptionsSourceInterface;
use Drupal\canvas\OptionsSource\OptionsSourceManager;
use Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\canvas\PropShape\EphemeralPropShapeRepository;
use Drupal\canvas\PropShape\ObjectPropsStorablePropShape;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropShape\PropShapeRepositoryInterface;
use Drupal\canvas\PropShape\StorablePropShape;
use Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\Component\ComponentMetadata;
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
   *
   * @throws \Drupal\canvas\ComponentDoesNotMeetRequirementsException
   *   When the component does not meet requirements.
   */
  public static function check(string $component_id, ComponentMetadata $metadata, array $required_props): void {
    $messages = [];

    if ($metadata->group == 'Elements') {
      $messages[] = 'Component uses the reserved "Elements" category';
    }

    // Every slot must have a title.
    foreach ($metadata->slots as $slot_name => $slot_definition) {
      if (!\array_key_exists('title', $slot_definition)) {
        $messages[] = \sprintf('Slot "%s" must have title', $slot_name);
      }
    }

    // Check fundamentals.
    $validator = new Validator();
    foreach ($metadata->schema['properties'] ?? [] as $prop_name => $prop) {
      // Since Drupal 11.5, `type` is whatever the component declared: a string
      // for a single type, an array only for a union of types.
      // @see https://www.drupal.org/node/3554720
      $prop_types = (array) $prop['type'];
      if (\in_array(Attribute::class, $prop_types, TRUE)) {
        continue;
      }

      // Enums must not have empty values.
      if (\array_key_exists('enum', $prop) && \in_array('', $prop['enum'], TRUE)) {
        $messages[] = \sprintf('Prop "%s" has an empty enum value.', $prop_name);
        continue;
      }

      // For array types, also check enum in items.
      $is_array_prop_type = \in_array('array', $prop_types, TRUE);
      if ($is_array_prop_type && isset($prop['items']['enum']) && \in_array('', $prop['items']['enum'], TRUE)) {
        $messages[] = \sprintf('Prop "%s" has an empty enum value in items.', $prop_name);
      }

      // Options from site data.
      // @see \Drupal\canvas\OptionsSource\OptionsSourceInterface
      $item_schema = $is_array_prop_type ? ($prop['items'] ?? []) : $prop;
      if (\array_key_exists(OptionsSourceInterface::KEY, $prop) && $is_array_prop_type) {
        $messages[] = \sprintf('Prop "%s": move `%s` into `items`, because it applies to each value.', $prop_name, OptionsSourceInterface::KEY);
        continue;
      }
      if (\array_key_exists(OptionsSourceInterface::KEY, $item_schema)) {
        $prefix = \sprintf('Prop "%s": `%s`:', $prop_name, OptionsSourceInterface::KEY);
        $item_type = ((array) ($item_schema['type'] ?? NULL))[0] ?? NULL;
        $options_messages = match (TRUE) {
          !\in_array($item_type, ['string', 'integer'], TRUE) => ['Needs a prop of type string or integer, or a list of them.'],
          \array_key_exists('enum', $item_schema) => ['Replaces `enum`: remove `enum`.'],
          \in_array($prop_name, $required_props, TRUE) => ['Needs an optional prop, because the site may have no options to choose from.'],
          // Examples are default values, but IDs differ between sites.
          \array_key_exists('examples', $prop) => ['Remove `examples`: an example would become the default value, but option IDs differ between sites.'],
          default => \Drupal::service(OptionsSourceManager::class)->validatePropSchema($item_schema[OptionsSourceInterface::KEY], $item_type),
        };
        if ($options_messages) {
          $messages = [...$messages, ...\array_map(fn (string $message) => "$prefix $message", $options_messages)];
          continue;
        }
      }

      // Required props must have examples — except content-entity-reference
      // props, for which `examples` are explicitly unsupported (see below).
      $is_required_prop = \in_array($prop_name, $required_props, TRUE);
      if ($is_required_prop && !isset($prop['examples'][0]) && !JsonSchemaObjectRef::isContentEntityReference($prop)) {
        $messages[] = \sprintf('Prop "%s" is required, but does not have example value', $prop_name);
      }

      // Content-entity-reference props must not carry `examples`: the
      // referenced entity is resolved at runtime from
      // `dataDependencies.entityFields`, so an example value is meaningless.
      if (JsonSchemaObjectRef::isContentEntityReference($prop) && \array_key_exists('examples', $prop)) {
        $messages[] = \sprintf('Prop "%s" is a content-entity-reference prop and must not have examples.', $prop_name);
      }

      // Required array ("multiple cardinality") props must have `minItems: 1`.
      // JSON Schema's `required` keyword only means that the key must be
      // present, but it does not enforce that an array cannot be empty (`[]`).
      // That would make a required multiple-cardinality prop meaningless for a
      // content author: no values would be required.
      // To align with Content Author expectations, every required `type: array`
      // prop must hence also have `minItems: 1`. This also happens to align
      // exactly with Drupal's Field API semantics for a "required" field.
      if ($is_array_prop_type && $is_required_prop && (!\array_key_exists('minItems', $prop) || $prop['minItems'] < 1)) {
        $messages[] = \sprintf('Multiple-cardinality prop "%s" is required, but does not specify `minItems: 1`.', $prop_name);
      }
      // `minItems` only ever makes sense for required props.
      if ($is_array_prop_type && !$is_required_prop && \array_key_exists('minItems', $prop)) {
        $messages[] = \sprintf('Multiple-cardinality prop "%s" specifies `minItems`, but is not required. Only required multiple-cardinality props can specify `minItems`.', $prop_name);
      }

      // JSON Schema does not require that examples must be valid, but we do
      // require the first one to be, as we use it as the default value for
      // the prop.
      if (isset($prop['examples'][0])) {
        $example = $prop['examples'][0];
        // PHP's "associative arrays" are JSON's "objects". The JSON Schema
        // validator expects such "objects" to be \stdClass objects — at every
        // level of nesting, because custom object shapes may contain
        // object-shaped sub-properties (e.g. an image inside a group).
        if ($prop_types === ['object'] && \is_array($example)) {
          $example = self::exampleToValidatable($example);
        }
        // Similarly, for `type: array, items: {type: object}`.
        if ($is_array_prop_type && ($prop['items']['type'] ?? NULL) === 'object' && \is_array($example)) {
          $example = \array_map(
            fn (array|object $item) => self::exampleToValidatable((array) $item),
            $example,
          );
        }
        $validator->reset();
        $validator->validate($example, $prop);
        if (!$validator->isValid()) {
          $messages[] = \sprintf('Prop "%s" has invalid example value: %s', $prop_name, implode("\n", \array_map(
            static fn(array $error): string => \sprintf("[%s] %s", $error['property'], $error['message']),
            $validator->getErrors()
          )));
        }
      }

      // JSON Schema does not require that arrays allow >=2 items, but for the
      // use of the `type: array` type to make sense in Canvas, it is required
      // that IF `maxItems` is specified, it is >1. Because a single-value array
      // would be a pointless (array) wrapper for a component prop. (And `0` for
      // an empty array would make even less sense, let alone negative numbers.)
      if ($is_array_prop_type && \array_key_exists('maxItems', $prop) && $prop['maxItems'] < 2) {
        $messages[] = \sprintf('The "maxItems" restriction on arrays (if set) must be at least 2, but got %d on prop "%s". Use a non-array type for single-value props.', $prop['maxItems'], $prop_name);
      }

      // Validation for the additional functionality overlaid on top of the SDC
      // JSON Schema.
      // @see docs/shape-matching-into-field-types.md#3.2.1
      if (\array_key_exists('contentMediaType', $prop) && $prop['contentMediaType'] === 'text/html' && isset($prop['x-formatting-context'])) {
        if (!\in_array($prop['x-formatting-context'], ['inline', 'block'], TRUE)) {
          $messages[] = \sprintf('Invalid value "%s" for "x-formatting-context". Valid values are "inline" and "block".', $prop['x-formatting-context']);
          continue;
        }
      }

      // Validation for x-canvas-color-picker.
      if (isset($prop['x-canvas-color-picker'])) {
        if (!\in_array($prop['x-canvas-color-picker'], ['kit-only', 'kit-and-free'], TRUE)) {
          $messages[] = \sprintf('Invalid value "%s" for "x-canvas-color-picker". Valid values are "kit-only" and "kit-and-free".', $prop['x-canvas-color-picker']);
          continue;
        }
      }

      // Validation for x-canvas-enum-icons: a map of enum value to icon name.
      // The UI knows which icon names exist and shows the label for the rest.
      // @see ui/src/components/form/components/SegmentedSelect.tsx
      if (\array_key_exists('x-canvas-enum-icons', $prop)) {
        $icons = $prop['x-canvas-enum-icons'];
        $enum = \array_map('strval', $prop['enum'] ?? []);
        if (!\is_array($icons) || \array_diff(\array_map('strval', \array_keys($icons)), $enum) !== [] || \array_filter($icons, static fn (mixed $icon): bool => !\is_string($icon) || !\preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $icon)) !== []) {
          $messages[] = \sprintf('"x-canvas-enum-icons" on prop "%s" must map values of its "enum" to icon names such as "text-align-left".', $prop_name);
          continue;
        }
      }

      // Validation for x-canvas-unit: the unit that the component instance form
      // shows next to a number input, such as "px". A prop with an `enum` gets
      // a select, which has no place for it.
      // @see ui/src/components/form/components/TextField.tsx
      if (\array_key_exists('x-canvas-unit', $prop)) {
        $unit = $prop['x-canvas-unit'];
        if (!\in_array($prop_types[0] ?? NULL, ['number', 'integer'], TRUE) || \array_key_exists('enum', $prop) || !\is_string($unit) || \trim($unit) === '' || \mb_strlen($unit) > 8) {
          $messages[] = \sprintf('"x-canvas-unit" on prop "%s" requires a number or integer prop without "enum", and must be a non-empty string of at most 8 characters, such as "px".', $prop_name);
          continue;
        }
      }

      // Validation for x-canvas-object-display: whether the component instance
      // form shows the widgets of a single-value custom object prop ("group")
      // in a popover or in the form itself. The items of a multi-value group
      // are always rows with a popover.
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::buildObjectPropForm()
      if (\array_key_exists('x-canvas-object-display', $prop) || ($is_array_prop_type && \array_key_exists('x-canvas-object-display', $prop['items'] ?? []))) {
        if ($prop_types !== ['object'] || !\is_array($prop['properties'] ?? NULL)) {
          $messages[] = \sprintf('"x-canvas-object-display" on prop "%s" requires a single-value object prop with "properties".', $prop_name);
          continue;
        }
        if (!\in_array($prop['x-canvas-object-display'], ['inline', 'popover'], TRUE)) {
          $messages[] = \sprintf('"x-canvas-object-display" on prop "%s" must be "inline" or "popover".', $prop_name);
          continue;
        }
      }

      // Validation for x-canvas-group and x-canvas-advanced: the collapsible
      // section of the component instance form that shows the prop.
      // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::preRenderPropSections()
      if (\array_key_exists('x-canvas-group', $prop) && (!\is_string($prop['x-canvas-group']) || \trim($prop['x-canvas-group']) === '')) {
        $messages[] = \sprintf('"x-canvas-group" on prop "%s" must be a non-empty string: the label of its section.', $prop_name);
        continue;
      }
      if (\array_key_exists('x-canvas-advanced', $prop)) {
        $advanced_message = match (TRUE) {
          !\is_bool($prop['x-canvas-advanced']) => '"x-canvas-advanced" on prop "%s" must be true or false.',
          !$prop['x-canvas-advanced'] => NULL,
          \array_key_exists('x-canvas-group', $prop) => 'Prop "%s" has both "x-canvas-group" and "x-canvas-advanced": remove one, because a prop shows in one section.',
          $is_required_prop => 'Prop "%s" is required, so it cannot have "x-canvas-advanced": an author would miss it in the collapsed section.',
          default => NULL,
        };
        if ($advanced_message !== NULL) {
          $messages[] = \sprintf($advanced_message, $prop_name);
          continue;
        }
      }

      // Validation for x-canvas-color-folders.
      if (\array_key_exists('x-canvas-color-folders', $prop)) {
        $folders = $prop['x-canvas-color-folders'];
        if (($prop['$ref'] ?? NULL) !== 'json-schema-definitions://canvas.module/color') {
          $messages[] = \sprintf('"x-canvas-color-folders" on prop "%s" requires $ref: json-schema-definitions://canvas.module/color.', $prop_name);
          continue;
        }
        if (!\is_array($folders) || empty($folders)) {
          $messages[] = \sprintf('"x-canvas-color-folders" on prop "%s" must be a non-empty array of folder UUIDs.', $prop_name);
          continue;
        }
        foreach ($folders as $folder_id) {
          if (!\is_string($folder_id) || $folder_id === '') {
            $messages[] = \sprintf('"x-canvas-color-folders" on prop "%s" must contain only non-empty strings.', $prop_name);
            break;
          }
        }
      }

      // Validation for content-entity-reference props.
      // ContentEntityReferenceObjectConstraint is registered as the JSON Schema
      // "object" constraint, but it only fires when validating prop *values*
      // (ComponentValidator::validateProps), not at metadata-derivation time —
      // hence the explicit static call below.
      // @see docs/shape-matching.md#3.2.3
      // @see \Drupal\canvas\CanvasServiceProvider::alter()
      // @see \Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint
      if (JsonSchemaObjectRef::isContentEntityReference($prop)) {
        if (\in_array($prop_name, $required_props, TRUE)) {
          $messages[] = \sprintf('Prop "%s" is required, but content-entity-reference props must be optional.', $prop_name);
          continue;
        }
        $schema_errors = ContentEntityReferenceObjectConstraint::validateMetadataSchema($prop, $prop_name);
        if (!empty($schema_errors)) {
          $messages = [...$messages, ...$schema_errors];
          continue;
        }
      }

      // Every prop must have a title.
      if (!isset($prop['title'])) {
        $messages[] = \sprintf('Prop "%s" must have title', $prop_name);
      }
    }

    $messages = [...$messages, ...PropVisibilityCondition::validateProps($metadata->schema['properties'] ?? [])];

    // Do not try computing any StorablePropShape if one or more fundamentals
    // are not right.
    if (!empty($messages)) {
      throw new ComponentDoesNotMeetRequirementsException($messages);
    }

    // Every prop must have a StorablePropShape. If an example is provided, it
    // must be considered non-empty by the field type that will power it.
    $props_for_metadata = JsonSchemaPropsComponentSourceBase::getComponentInputsForMetadata($component_id, $metadata);
    /** @var \Drupal\canvas\PropShape\PropShapeRepositoryInterface $prop_shape_repository */
    $prop_shape_repository = \Drupal::service(EphemeralPropShapeRepository::class);
    foreach ($props_for_metadata as $cpe => $prop_shape) {
      $prop_name = ComponentPropExpression::fromString($cpe)->propName;
      $storable_prop_shape = $prop_shape_repository->getStorablePropShape($prop_shape);
      // Custom object shapes ("groups"): composed per-sub-property shapes. If
      // an example is provided, each sub-property's example value must be
      // usable by that sub-property's field type — except entity-referencing
      // sub-properties (e.g. images), whose example values are resolved at
      // runtime instead of being stored.
      // @see \Drupal\canvas\PropSource\DefaultRelativeUrlPropSource
      if ($storable_prop_shape instanceof ObjectPropsStorablePropShape) {
        $example = $metadata->schema['properties'][$prop_name]['examples'][0] ?? NULL;
        if (!\is_array($example)) {
          // A missing example is allowed (for optional props); a non-array
          // example was already rejected by the JSON Schema validation above.
          continue;
        }
        $example_items = $storable_prop_shape->cardinality === NULL ? [$example] : $example;
        foreach ($example_items as $example_item) {
          if (!\is_array($example_item)) {
            continue;
          }
          foreach ($storable_prop_shape->subShapes as $sub_property_name => $sub_storable_prop_shape) {
            if (!\array_key_exists($sub_property_name, $example_item)) {
              continue;
            }
            if (JsonSchemaPropsComponentSourceBase::exampleValueRequiresEntity($sub_storable_prop_shape)) {
              continue;
            }
            try {
              $sub_storable_prop_shape->toStaticPropSource()->withValue($example_item[$sub_property_name]);
            }
            catch (\LogicException) {
              $messages[] = \sprintf('Prop "%s" example value `%s` for the "%s" sub-property cannot be used as a default.', $prop_name, \json_encode($example_item[$sub_property_name]), $sub_property_name);
            }
          }
        }
        continue;
      }
      if (!$storable_prop_shape instanceof StorablePropShape) {
        // For custom object shapes, name the unsupported sub-properties.
        $sub_property_messages = self::checkObjectPropSubProperties($prop_name, $prop_shape, $prop_shape_repository);
        if ($sub_property_messages !== []) {
          $messages = [...$messages, ...$sub_property_messages];
          continue;
        }
        $messages[] = \sprintf('Drupal Canvas does not know of a field type/widget to allow populating the <code>%s</code> prop, with the shape <code>%s</code>.', $prop_name, json_encode($prop_shape->schema, JSON_UNESCAPED_SLASHES));
        continue;
      }
      // Entity-referencing props skip the StaticPropSource pipeline at runtime,
      // so don't trial them here either.
      if (JsonSchemaPropsComponentSourceBase::exampleValueRequiresEntity($storable_prop_shape)) {
        continue;
      }
      $example = $metadata->schema['properties'][$prop_name]['examples'][0] ?? NULL;
      if ($example === NULL) {
        continue;
      }
      try {
        $storable_prop_shape->toStaticPropSource()->withValue($example);
      }
      catch (\LogicException) {
        $messages[] = \sprintf('Prop "%s" example value `%s` cannot be used as a default.', $prop_name, \json_encode($example));
      }
    }

    if (!empty($messages)) {
      throw new ComponentDoesNotMeetRequirementsException($messages);
    }
  }

  /**
   * Names the unsupported sub-properties of a custom object shape, if any.
   *
   * @return list<string>
   *   One message per unsupported sub-property; empty when the given prop
   *   shape is not a custom object shape, or when all of its sub-properties
   *   are supported.
   */
  private static function checkObjectPropSubProperties(string $prop_name, PropShape $prop_shape, PropShapeRepositoryInterface $prop_shape_repository): array {
    $schema = $prop_shape->schema;
    // For multi-value groups, the object shape is the item shape.
    $object_schema = ($schema['type'] ?? NULL) === 'array' ? $schema['items'] ?? [] : $schema;
    if (($object_schema['type'] ?? NULL) !== 'object' || !\is_array($object_schema['properties'] ?? NULL)) {
      return [];
    }
    $messages = [];
    foreach ($object_schema['properties'] as $sub_property_name => $sub_property_schema) {
      $sub_shape = PropShape::normalize($sub_property_schema);
      // Nested custom object shapes resolve to a composite, which is not
      // supported inside a group: the 1-level depth limit.
      if (!$prop_shape_repository->getStorablePropShape($sub_shape) instanceof StorablePropShape) {
        $messages[] = \sprintf('Drupal Canvas does not know of a field type/widget to allow populating the <code>%s</code> sub-property of the <code>%s</code> prop, with the shape <code>%s</code>.', $sub_property_name, $prop_name, json_encode($sub_shape->schema, JSON_UNESCAPED_SLASHES));
      }
    }
    return $messages;
  }

  /**
   * Recursively converts an object example to what the JSON validator expects.
   *
   * PHP associative arrays become \stdClass objects; lists stay arrays.
   *
   * @param array<string, mixed> $example
   *   An object-shaped example value.
   */
  private static function exampleToValidatable(array $example): object {
    // The top level is always an object, even when the example is (invalidly)
    // empty or a list: the JSON Schema validator then reports the mismatch.
    return (object) \json_decode(\json_encode($example, \JSON_THROW_ON_ERROR), FALSE, flags: \JSON_THROW_ON_ERROR);
  }

}
