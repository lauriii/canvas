<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Form;

/**
 * A form element that can describe itself as data.
 *
 * The describer knows the shape of core's own elements, but it cannot know
 * contrib's: this site alone has 78 registered element types, and the number
 * is unbounded. A central mapping from #type to JSON Schema is therefore the
 * wrong direction, and it is the direction core rejected the last time this
 * was attempted.
 *
 * An element plugin implementing this answers for itself, and a form using it
 * becomes describable without the describer learning anything.
 *
 * @see https://www.drupal.org/project/drupal/issues/2913372
 */
interface JsonSchemaFormElementInterface {

  /**
   * Returns the JSON Schema for this element's value.
   *
   * Static, like Drupal's other element callbacks, so that the describer
   * works from the plugin definition without instantiating anything.
   *
   * @param array $element
   *   The built element, so that per-instance constraints such as #maxlength
   *   or #options can be reflected in the schema.
   *
   * @return array|null
   *   The schema, or NULL when this instance cannot be described after all,
   *   in which case it is reported as unsupported rather than guessed at.
   */
  public static function toJsonSchema(array $element): ?array;

}
