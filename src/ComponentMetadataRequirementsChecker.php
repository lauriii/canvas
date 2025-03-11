<?php

declare(strict_types=1);

namespace Drupal\experience_builder;

use Drupal\Core\Theme\Component\ComponentMetadata;
use Drupal\experience_builder\PropExpressions\Component\ComponentPropExpression;
use Drupal\experience_builder\PropShape\PropShape;
use Drupal\experience_builder\PropShape\StorablePropShape;

/**
 * Defines a class for checking if component metadata meets requirements.
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
   * @throws \Drupal\experience_builder\ComponentDoesNotMeetRequirementsException
   *   When the component does not meet requirements.
   */
  public static function check(string $component_id, ComponentMetadata $metadata, array $required_props): void {
    $messages = [];
    // XB always requires schema, even for theme components.
    // @see \Drupal\Core\Theme\ComponentPluginManager::shouldEnforceSchemas()
    // @see \Drupal\Core\Theme\Component\ComponentMetadata::parseSchemaInfo()
    if ($metadata->schema === NULL) {
      throw new ComponentDoesNotMeetRequirementsException(['Component has no props schema']);
    }

    if ($metadata->group == 'Elements') {
      $messages[] = 'Component uses the reserved "Elements" category';
    }

    $missing_examples = \array_filter(
      \array_intersect_key($metadata->schema['properties'] ?? [], \array_flip($required_props)),
      static fn (array $property) => empty($property['examples'])
    );
    if (\count($missing_examples) > 0) {
      $messages += \array_map(static fn(string $prop) => \sprintf('Prop "%s" is required, but does not have example value', $prop), \array_keys($missing_examples));
    }

    $props_for_metadata = PropShape::getComponentPropsForMetadata($component_id, $metadata);
    foreach ($metadata->schema['properties'] as $prop_name => $prop) {
      if ($prop_name === 'attributes') {
        continue;
      }
      // Every prop must have a title.
      if (!isset($prop['title'])) {
        $messages[] = \sprintf('Prop "%s" must have title', $prop_name);
      }
      // Every prop must have a StorablePropShape.
      $component_prop_expression = new ComponentPropExpression($component_id, $prop_name);
      $prop_shape = $props_for_metadata[(string) $component_prop_expression];
      $storable_prop_shape = $prop_shape->getStorage();
      if ($storable_prop_shape instanceof StorablePropShape) {
        continue;
      }
      $messages[] = \sprintf('Experience Builder does not know of a field type/widget to allow populating the <code>%s</code> prop, with the shape <code>%s</code>.', $prop_name, json_encode($prop_shape->schema, JSON_UNESCAPED_SLASHES));
    }
    if (!empty($messages)) {
      throw new ComponentDoesNotMeetRequirementsException($messages);
    }
  }

}
