<?php

declare(strict_types=1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(
  id: 'JsComponentHasValidSdcMetadata',
  label: new TranslatableMarkup('Maps to valid SDC definition.', [], ['context' => 'Validation']),
  type: [
    'experience_builder.js_component.*',
  ],
)]
final class JsComponentHasValidSdcMetadataConstraint extends SymfonyConstraint {}
