<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects entity field expressions that target an internal field or property.
 *
 * A field or field property is excluded when it is internal but not computed.
 *
 * @see \Drupal\canvas\Controller\ApiUiContentEntityReferenceControllers::buildFieldEntry()
 * @see \Drupal\Core\TypedData\DataDefinitionInterface::isInternal()
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Entity field expression must not target an internal field property.", [], ['context' => 'Validation']),
  type: "string",
)]
final class EntityFieldExpressionMustNotTargetInternalPropertyConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionMustNotTargetInternalProperty';

  /**
   * The error message.
   */
  public string $message = "The field property '@field.@property' is internal and cannot be referenced.";

}
