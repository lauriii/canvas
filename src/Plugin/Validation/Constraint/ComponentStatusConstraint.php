<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'ComponentStatusConstraint',
  label: new TranslatableMarkup('Validates the component status', [], ['context' => 'Validation']),
)]
final class ComponentStatusConstraint extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public string $message = "The component '%component' cannot be enabled because it does not meet the requirements of Experience Builder.";

}
