<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks every entity-field expression survives the picker's expand/coalesce.
 *
 * The content selection UI exposes each stored
 * `dataDependencies.entityFields.<prop>` entry to the editor as atomic field
 * selections (`Coalescer::expand()`), and re-combines the edited selections on
 * save (`Coalescer::coalesce()`). Expanding drops object-prop names, which
 * re-coalescing then derives from each leaf's developer-facing key. An entry
 * whose object-prop names are not those canonical keys does not survive this
 * trip — its names would silently change — so it is rejected here, keeping the
 * picker's expand → edit → coalesce round trip lossless.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\Coalescer
 * @see \Drupal\canvas\Plugin\Validation\Constraint\EntityFieldExpressionsSameFieldMustBeCoalescedConstraint
 * @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface::getDeveloperFacingKey()
 * @see docs/adr/0005-Keep-the-front-end-simple.md
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Entity-field expressions must survive the picker's expand/coalesce round trip.", [], ['context' => 'Validation']),
  type: "sequence",
)]
final class EntityFieldExpressionsMustBeIdempotentConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionsMustBeIdempotent';

  /**
   * The error message.
   */
  public string $message = "The expression '@expression' cannot be reproduced by the content selection UI; expanding and re-combining it yields '@normalized'. Its object property names must be the field property or referenced-field name.";

}
