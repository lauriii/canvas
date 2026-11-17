<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * @internal
 */
#[Constraint(
  id: 'ThemeRegionExists',
  label: new TranslatableMarkup('@todo', [], ['context' => 'Validation']),
  type: ['string']
)]
class ThemeRegionExistsConstraint extends SymfonyConstraint {

  #[HasNamedArguments]
  public function __construct(
    // The machine name of the theme for which this must be a valid region.
    public string $theme,
    // The violation message when the region does not exist.
    public string $message = "Region '@region' does not exist in theme '@theme'.",
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
