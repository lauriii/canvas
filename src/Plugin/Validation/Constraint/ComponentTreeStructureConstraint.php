<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates a component tree structure.
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Validates the component tree structure', [], ['context' => 'Validation']),
)]
class ComponentTreeStructureConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'ComponentTreeStructure';

  #[HasNamedArguments]
  public function __construct(
    // The property path that violations are reported under.
    public string $basePropertyPath = '',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct(groups: $groups, payload: $payload);
  }

}
