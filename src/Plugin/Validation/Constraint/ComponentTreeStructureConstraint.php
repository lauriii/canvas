<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates a component tree structure.
 */
#[Constraint(
  id: 'ComponentTreeStructure',
  label: new TranslatableMarkup('Validates the component tree structure', [], ['context' => 'Validation']),
)]
class ComponentTreeStructureConstraint extends SymfonyConstraint {

}
