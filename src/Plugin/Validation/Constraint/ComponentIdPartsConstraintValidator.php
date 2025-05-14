<?php

declare(strict_types = 1);

namespace Drupal\experience_builder\Plugin\Validation\Constraint;

use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ComponentIdPartsConstraint constraint.
 */
class ComponentIdPartsConstraintValidator extends StringPartsConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!is_string($value)) {
      throw new UnexpectedTypeException($value, 'string');
    }
    if (!$constraint instanceof ComponentIdPartsConstraint) {
      throw new UnexpectedTypeException($constraint, ComponentIdPartsConstraint::class);
    }

    $data = $this->context->getObject();
    assert($data instanceof TypedDataInterface);

    try {
      $entity = $data->getParent();
      \assert($entity instanceof ComplexDataInterface);
      $source = $entity->get('source')->getValue();
      if (\in_array($source, $constraint->ignoreSources, TRUE)) {
        return;
      }
    }
    catch (\InvalidArgumentException | MissingDataException) {
    }

    parent::validate($value, $constraint);
  }

}
