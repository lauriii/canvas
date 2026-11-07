<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates `type: object` prop shapes: well-known `$ref` XOR custom shape.
 *
 * The 1-level depth limit for custom object shapes ("groups") is enforced
 * here: sub-properties must not declare an inline object (`properties`) —
 * neither directly nor inside `items` — and must not declare
 * `contentMediaType` (formatted text). `$ref` sub-properties and arrays of
 * scalars are allowed.
 *
 * This constraint is attached to `canvas.json_schema.prop_shape.object`, so it
 * fires both for top-level object props and for the `items` of `type: array`
 * props (multi-value groups).
 */
final class ValidCanvasObjectPropShapeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ValidCanvasObjectPropShapeConstraint) {
      throw new UnexpectedTypeException($constraint, ValidCanvasObjectPropShapeConstraint::class);
    }
    if ($value === NULL) {
      return;
    }
    if (!\is_array($value)) {
      throw new UnexpectedValueException($value, 'array');
    }

    $has_ref = \array_key_exists('$ref', $value);
    $has_properties = \array_key_exists('properties', $value);
    if ($has_ref && $has_properties) {
      $this->context->addViolation($constraint->bothMessage);
      return;
    }
    if (!$has_ref && !$has_properties) {
      $this->context->addViolation($constraint->neitherMessage);
      return;
    }

    if (\array_key_exists('required', $value) && !$has_properties) {
      $this->context->addViolation($constraint->requiredWithoutPropertiesMessage);
    }

    if (!$has_properties || !\is_array($value['properties'])) {
      return;
    }

    foreach ($value['properties'] as $sub_property_name => $sub_property) {
      if (!\is_array($sub_property)) {
        continue;
      }
      // For arrays of scalars, the restrictions apply to the item shape.
      $shape_to_check = ($sub_property['type'] ?? NULL) === 'array' && \is_array($sub_property['items'] ?? NULL)
        ? $sub_property['items']
        : $sub_property;
      if (\array_key_exists('properties', $shape_to_check)) {
        $this->context->addViolation($constraint->nestedObjectMessage, ['%sub_property' => (string) $sub_property_name]);
      }
      if (\array_key_exists('contentMediaType', $shape_to_check)) {
        $this->context->addViolation($constraint->contentMediaTypeMessage, ['%sub_property' => (string) $sub_property_name]);
      }
    }

    foreach ($value['required'] ?? [] as $required_sub_property) {
      if (!\array_key_exists($required_sub_property, $value['properties'])) {
        $this->context->addViolation($constraint->unknownRequiredMessage, ['%sub_property' => (string) $required_sub_property]);
      }
    }
  }

}
