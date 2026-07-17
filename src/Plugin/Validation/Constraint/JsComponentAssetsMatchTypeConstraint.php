<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires assets for React Code Components.
 */
#[Constraint(
  id: 'JsComponentAssetsMatchType',
  label: new TranslatableMarkup('Code Component assets match its type.', [], ['context' => 'Validation']),
  type: [
    'canvas.js_component.*',
  ],
)]
final class JsComponentAssetsMatchTypeConstraint extends SymfonyConstraint {

  public string $assetsRequiredMessage = 'React code components must contain JavaScript and CSS.';

}
