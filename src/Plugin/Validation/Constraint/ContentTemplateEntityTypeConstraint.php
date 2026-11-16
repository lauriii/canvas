<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates that a value is an entity type that supports Canvas templates.
 */
#[Constraint(
  id: 'ContentTemplateEntityType',
  label: new TranslatableMarkup('Valid content template entity type', [], ['context' => 'Validation']),
  type: ['string']
)]
final class ContentTemplateEntityTypeConstraint extends SymfonyConstraint {

  public string $message = 'Content templates are not available for this entity type.';

}
