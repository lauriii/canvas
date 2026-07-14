<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Entity\JavaScriptComponent;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the asset shape of a Code Component.
 */
final class JsComponentAssetsMatchTypeConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $data, Constraint $constraint): void {
    if (!$constraint instanceof JsComponentAssetsMatchTypeConstraint) {
      throw new UnexpectedTypeException($constraint, JsComponentAssetsMatchTypeConstraint::class);
    }
    if (!$data instanceof JavaScriptComponent) {
      throw new UnexpectedValueException($data, JavaScriptComponent::class);
    }

    foreach (['js', 'css'] as $property) {
      $has_assets = $data->get($property) !== NULL;
      if ($data->isExternal() === $has_assets) {
        $this->context->buildViolation(
          $data->isExternal()
            ? $constraint->assetsForbiddenMessage
            : $constraint->assetsRequiredMessage,
        )->atPath($property)->addViolation();
      }
    }
  }

}
