<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('Canvas config entity translations are valid', [], ['context' => 'Validation']),
  type: ['entity'],
)]
class CanvasConfigEntityTranslationsAreValidConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'CanvasConfigEntityTranslationsAreValid';

}
