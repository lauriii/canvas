<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\DataType\ComponentStylesData;
use Drupal\canvas\Style\ComponentStyles;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Rejects only shape errors, so theme changes never block saves.
 */
final class ValidComponentStylesConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $object = $this->context->getObject();
    if ($object instanceof ComponentStylesData) {
      try {
        $value = $object->getDecoded();
      }
      catch (\Throwable) {
        $this->context->addViolation('Styles must be a map of style ID to option ID.');
        return;
      }
    }
    foreach (ComponentStyles::validateShape($value) as $error) {
      $builder = $this->context->buildViolation($error['message']);
      // Config sequences validate each key separately; point at the entry.
      if ($error['key'] !== NULL && !$object instanceof ComponentStylesData) {
        $builder->atPath($error['key']);
      }
      $builder->addViolation();
    }
  }

}
