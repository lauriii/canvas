<?php

declare(strict_types=1);

namespace Drupal\canvas\Validation\JsonSchema;

use JsonSchema\ConstraintError;

/**
 * Custom validation errors for optional JSON Schema validation additions.
 */
class CustomConstraintError extends ConstraintError {

  /**
   * @see \Drupal\canvas\Plugin\Validation\Constraint\UriConstraintValidator
   */
  public const X_ALLOWED_SCHEMES = 'x-allowed-schemes';

  /**
   * @see \Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint
   */
  public const X_ALLOWED_ENTITY_TYPE_ID_MISSING = 'x-allowed-entity-type-id-missing';

  /**
   * @see \Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint
   */
  public const X_ALLOWED_ENTITY_TYPE_ID_INVALID = 'x-allowed-entity-type-id-invalid';

  /**
   * @see \Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint
   */
  public const X_ALLOWED_BUNDLE_REQUIRED = 'x-allowed-bundle-required';

  /**
   * @see \Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint
   */
  public const X_ALLOWED_BUNDLE_NOT_APPLICABLE = 'x-allowed-bundle-not-applicable';

  /**
   * @see \Drupal\canvas\Validation\JsonSchema\ContentEntityReferenceObjectConstraint
   */
  public const X_ALLOWED_BUNDLE_INVALID = 'x-allowed-bundle-invalid';

  public function getMessage(): string {
    $name = $this->getValue();
    return match ($name) {
      self::X_ALLOWED_SCHEMES => 'The "%s" URI scheme is not allowed',
      self::X_ALLOWED_ENTITY_TYPE_ID_MISSING => 'Missing "x-allowed-entity-type-id" for content entity reference prop "%s".',
      self::X_ALLOWED_ENTITY_TYPE_ID_INVALID => 'Invalid value "%s" for "x-allowed-entity-type-id": not a known content entity type.',
      self::X_ALLOWED_BUNDLE_REQUIRED => 'Missing "x-allowed-bundle" for content entity reference prop "%s" that is referencing entity type "%s" with bundles.',
      self::X_ALLOWED_BUNDLE_NOT_APPLICABLE => '"x-allowed-bundle" is specified for content entity reference prop "%s" that is referencing entity type "%s" that has no bundles.',
      self::X_ALLOWED_BUNDLE_INVALID => 'Invalid value "%s" for "x-allowed-bundle": not a known bundle of entity type "%s".',
      default => parent::getMessage(),
    };
  }

}
