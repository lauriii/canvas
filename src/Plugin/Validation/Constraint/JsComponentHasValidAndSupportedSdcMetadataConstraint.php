<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'JsComponentHasValidAndSupportedSdcMetadata',
  // @see docs/shape-matching-into-field-types.md, section 3.1.2.b
  label: new TranslatableMarkup('Maps to valid SDC definition, and meets XB requirements.', [], ['context' => 'Validation']),
  type: [
    'experience_builder.js_component.*',
  ],
)]
final class JsComponentHasValidAndSupportedSdcMetadataConstraint extends SymfonyConstraint {}
