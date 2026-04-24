<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Validates an entity field expression targets an existing entity type+bundle.
 */
#[Constraint(
  id: "ExpressionTargetEntityBundleExists",
  label: new TranslatableMarkup("Entity field expression targets an existing entity type and bundle.", [], ['context' => 'Validation']),
  type: "string",
)]
final class ExpressionTargetEntityBundleExistsConstraint extends SymfonyConstraint {

  /**
   * Error when the entity type does not exist.
   */
  public string $entityTypeNotFoundMessage = "The entity type '@entity_type' does not exist.";

  /**
   * Error when the bundle does not exist.
   */
  public string $bundleNotFoundMessage = "The entity type '@entity_type' does not have a '@bundle' bundle.";

}
