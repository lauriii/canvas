<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Validates that the number of color components matches the color space.
 *
 * Known color spaces and their required component counts are declared in
 * COMPONENT_COUNTS. To support a new color space, add an entry to that map.
 * Color spaces that are not present in the map are intentionally skipped so
 * that future additions do not require a simultaneous constraint update.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'ColorComponentCount',
  label: new TranslatableMarkup('Color component count', [], ['context' => 'Validation']),
  type: 'sequence',
)]
final class ColorComponentCountConstraint extends Constraint {

  /**
   * Maps known color spaces to their required number of components.
   *
   * To add support for a new color space, append an entry here. Color spaces
   * not present in this map are silently allowed, so introducing a new space
   * does not require touching this constraint.
   *
   * @var array<string, int>
   */
  public const array COMPONENT_COUNTS = [
    'srgb' => 3,
    'hsl' => 3,
  ];

  /**
   * The violation message.
   *
   * Available parameters:
   * - %colorSpace: the color-space identifier.
   * - %expected: the required number of components.
   * - %actual: the number of components actually provided.
   */
  public string $message = 'The %colorSpace color space requires exactly %expected component(s), but %actual were provided.';

}
