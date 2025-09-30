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

  public function getMessage(): string {
    $name = $this->getValue();
    if ($name === self::X_ALLOWED_SCHEMES) {
      return 'The "%s" URI scheme is not allowed';
    }
    return parent::getMessage();
  }

}
