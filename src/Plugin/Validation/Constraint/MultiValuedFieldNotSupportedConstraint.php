<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects entity field expressions using a multi-valued field.
 *
 * A delta-less expression on a multi-valued (cardinality other than 1) field
 * resolves to a delta-keyed array of values — or, for reference fields, of
 * entities. Neither is supported at render time: a scalar leaf would hand an
 * array to a scalar SDC prop, and JsComponent::buildReferencePayload() does
 * not descend into an array of entities, so the referenced data would
 * silently never arrive. A delta-specific expression would render to a single
 * value, but the content-entity-reference picker cannot compose one. Storing
 * either is rejected for now.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\Evaluator
 * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::buildReferencePayload()
 * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3589536
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Multi-valued fields are not supported.", [], ['context' => 'Validation']),
  type: "string",
)]
final class MultiValuedFieldNotSupportedConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'MultiValuedFieldNotSupported';

  /**
   * The error message.
   */
  public string $message = "The field '@field' is multi-valued, which is not yet supported.";

}
