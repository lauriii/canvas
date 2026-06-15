<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the MultiTargetBundleReferenceNotSupported constraint.
 *
 * @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3591656
 */
final class MultiTargetBundleReferenceNotSupportedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof MultiTargetBundleReferenceNotSupportedConstraint) {
      throw new UnexpectedTypeException($constraint, MultiTargetBundleReferenceNotSupportedConstraint::class);
    }

    if ($value === NULL || !\is_string($value)) {
      return;
    }

    try {
      $parsed = StructuredDataPropExpression::fromString($value);
    }
    catch (\Throwable) {
      // Invalid expressions are handled by ValidStructuredDataPropExpression.
      return;
    }

    if (!$parsed instanceof ReferenceFieldPropExpression) {
      return;
    }

    $multi_bundle_reference = $parsed->findMultiTargetBundleReference();
    if ($multi_bundle_reference !== NULL) {
      $this->context->addViolation($constraint->message, [
        '@field' => \sprintf(
          '%s.%s',
          $multi_bundle_reference->getHostEntityDataDefinition()->getDataType(),
          $multi_bundle_reference->getFieldName(),
        ),
      ]);
    }
  }

}
