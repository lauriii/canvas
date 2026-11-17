<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
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

  #[HasNamedArguments]
  public function __construct(
    public string $id,
    // The violation message when the CSS variable is already in use.
    public string $notUnique = 'CSS variable %value is already in use by another color.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
