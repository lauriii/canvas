<?php

declare(strict_types=1);

namespace Drupal\xb_test_invalid_field\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Checks that the field value is not 'invalid constraint'.
 *
 * @Constraint(
 *   id = "XbTestInvalidFieldConstraint",
 *   label = @Translation("XB Test Invalid Field Constraint")
 * )
 */
class XbTestInvalidFieldConstraint extends Constraint {

  public string $message = 'The value "invalid constraint" is not allowed in this field.';

}
