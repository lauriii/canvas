<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation\JsonSchema;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use JsonSchema\Constraints\FormatConstraint;
use JsonSchema\Entity\JsonPointer;

/**
 * Defines a custom JSON Schema "format" constraint validator.
 *
 * This applies custom validation errors for optional JSON Schema validation
 * additions to `type: string, format: …` schemas.
 *
 * Adds:
 * - `x-allowed-schemes` to `format: uri|uri-reference|iri|iri-reference`
 */
final class UriSchemeAwareFormatConstraint extends FormatConstraint {

  /**
   * {@inheritdoc}
   */
  public function check(&$element, $schema = NULL, ?JsonPointer $path = NULL, $i = NULL): void {
    if (!isset($schema->format) || $this->factory->getConfig(self::CHECK_MODE_DISABLE_FORMAT)) {
      return;
    }

    $before = $this->numErrors();
    parent::check($element, $schema, $path, $i);
    $after = $this->numErrors();

    // Expand the check for valid URIs & URI references, to also validate
    // `x-allowed-schemes`, if specified.
    // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat::toDataTypeShapeRequirements()
    if ($after === $before && in_array($schema->format, [JsonSchemaStringFormat::Iri->value, JsonSchemaStringFormat::IriReference->value, JsonSchemaStringFormat::Uri->value, JsonSchemaStringFormat::UriReference->value], TRUE)) {
      \assert(is_string($element));
      if (!property_exists($schema, 'x-allowed-schemes')) {
        return;
      }
      $allowed_schemes = $schema->{'x-allowed-schemes'};
      \assert(is_array($allowed_schemes));
      // If an absolute URL was given, also validate the scheme.
      // @see \Drupal\canvas\Plugin\Validation\Constraint\UriConstraintValidator
      $scheme = parse_url($element, PHP_URL_SCHEME);
      if (!is_null($scheme) && !in_array($scheme, $allowed_schemes, TRUE)) {
        // @phpstan-ignore-next-line staticMethod.notFound
        $this->addError(CustomConstraintError::X_ALLOWED_SCHEMES(), $path, ['scheme' => $scheme]);
      }
    }
  }

}
