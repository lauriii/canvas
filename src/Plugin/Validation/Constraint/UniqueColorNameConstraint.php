<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the color name is unique across all Color entities.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'UniqueColorNameConstraint',
  label: new TranslatableMarkup('Unique name per Color entity', [], ['context' => 'Validation']),
  type: ['string']
)]
final class UniqueColorNameConstraint extends Constraint {

  public string $id;

  public string $notUnique = 'Color name %value is already in use by another color.';

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['id'];
  }

}
