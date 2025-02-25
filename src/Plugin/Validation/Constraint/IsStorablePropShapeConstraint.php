<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'IsStorablePropShape',
  label: new TranslatableMarkup('Verifies a prop is storable by Experience Builder', [], ['context' => 'Validation']),
  type: ['mapping'],
)]
class IsStorablePropShapeConstraint extends SymfonyConstraint {

  /**
   * Violation message.
   *
   * @var string
   */
  public $message = 'Prop "%prop_name" has a shape that is unfortunately not supported by Experience Builder.';

}
