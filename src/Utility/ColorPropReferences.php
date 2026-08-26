<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\canvas\Entity\Color;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\DataType\ComponentInputs;

/**
 * Reads Brand Kit color references out of a component instance's inputs.
 *
 * A color prop stores its Brand Kit reference as an opaque string, so it is
 * invisible to PropSourceBase::calculateDependencies(), which derives
 * dependencies from a prop's field type and expression rather than its value.
 * This keeps the knowledge of that value format in one place, so the component
 * tree data model does not have to carry it.
 *
 * @internal
 */
final class ColorPropReferences {

  /**
   * Returns the config dependencies for the colors these inputs reference.
   *
   * @param \Drupal\canvas\Plugin\DataType\ComponentInputs $inputs
   *   A component instance's inputs.
   *
   * @return string[]
   *   Config dependency names of the referenced Color config entities.
   *
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::calculateDependencies()
   */
  public static function configDependencies(ComponentInputs $inputs): array {
    $dependencies = [];
    foreach ($inputs->getPropNamesByRef(JsonSchemaType::COLOR_SCHEMA_REF) as $prop_name) {
      // A literal CSS value (`#rrggbbaa`, `hsl(…)`) references no config.
      $color_id = ColorResolver::parseColorEntityId($inputs->getScalarPropValue($prop_name) ?? '');
      if ($color_id !== NULL) {
        $dependencies[] = 'canvas.' . Color::ENTITY_TYPE_ID . '.' . $color_id;
      }
    }
    return $dependencies;
  }

  /**
   * Returns the stored value that references the given color.
   *
   * @param string $color_id
   *   A Color config entity ID.
   *
   * @return string
   *   The value a color prop holds while it points at that Color.
   */
  public static function reference(string $color_id): string {
    return Color::REFERENCE_PREFIX . $color_id;
  }

}
