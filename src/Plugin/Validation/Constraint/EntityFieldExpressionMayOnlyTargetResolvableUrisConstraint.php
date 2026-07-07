<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rejects entity field expressions targeting a non-resolvable URI.
 *
 * A `uri`-typed field property is rejected unless it carries a
 * UriSchemeConstraint restricted to a subset of http/https, which guarantees
 * it resolves to a browser-accessible URL. A raw URI does not: e.g. a `link`
 * field's raw `uri` can be `entity:node/1`, and a `file_uri` field's raw
 * `value` is a stream-wrapper URI like `public://image.jpg`.
 *
 * @see \Drupal\Core\TypedData\Plugin\DataType\Uri
 * @see \Drupal\canvas\Utility\TypedDataHelper::isRestrictedToHttpSchemes()
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup("Entity field expression may only target a resolvable URI property.", [], ['context' => 'Validation']),
  type: "string",
)]
final class EntityFieldExpressionMayOnlyTargetResolvableUrisConstraint extends SymfonyConstraint {

  public const string PLUGIN_ID = 'EntityFieldExpressionMayOnlyTargetResolvableUris';

  /**
   * The error message.
   */
  public string $message = "The field property '@field.@property' is a raw URI, not guaranteed to resolve to a browser-accessible URL, and cannot be referenced.";

}
