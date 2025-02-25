<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\Config\Schema\Mapping;
use Drupal\experience_builder\JsonSchemaInterpreter\SdcPropJsonSchemaType;
use Drupal\experience_builder\PropShape\PropShape;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class IsStorablePropShapeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof IsStorablePropShapeConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\IsStorablePropShapeConstraint');
    }

    if (!is_array($value)) {
      // If the value is NULL, then the `NotNull` constraint validator will
      // set the appropriate validation error message.
      // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\NotNullConstraintValidator
      if ($value === NULL) {
        return;
      }
      throw new UnexpectedTypeException($value, 'array');
    }

    // Verify an absolute minimum of an JSON Schema definition is present. If
    // not, do not perform further validation; it's up to the other validation
    // constraints to consider this overall value invalid.
    // @todo Ideally, we'd only validate this if and only if all key-value pairs in this mapping entry are valid. That requires conditional/sequential execution of validation constraints, which Drupal does not currently support.
    // @see https://www.drupal.org/project/drupal/issues/2820364
    if (!array_key_exists('type', $value)) {
      return;
    }

    // If value 'type' is not supported by SdcPropJsonSchemaType, 'Choice'
    // constraint in config schema will set the appropriate validation message.
    // @see the `Choice` constraints on `type: experience_builder.js_component.*`'s for prop `type`.
    if (SdcPropJsonSchemaType::tryFrom($value['type']) === NULL) {
      return;
    }

    if (PropShape::normalize($value)->getStorage() == NULL) {
      $mapping = $this->context->getObject();
      assert($mapping instanceof Mapping);
      $this->context->buildViolation($constraint->message)
        ->setParameter('%prop_name', (string) $mapping->getName())
        ->addViolation();
    }
  }

}
