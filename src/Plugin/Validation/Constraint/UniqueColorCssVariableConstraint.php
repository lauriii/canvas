<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the CSS variable name is unique across all Color entities.
 *
 * @internal
 */
#[\Drupal\Core\Validation\Attribute\Constraint(
  id: 'UniqueColorCssVariableConstraint',
  label: new TranslatableMarkup('Unique CSS variable per Color entity', [], ['context' => 'Validation']),
  type: ['string']
)]
final class UniqueColorCssVariableConstraint extends Constraint {

  public string $id;

  public string $notUnique = 'CSS variable %value is already in use by another color.';

  /**
   * {@inheritdoc}
   */
  public function getRequiredOptions(): array {
    return ['id'];
  }

}
