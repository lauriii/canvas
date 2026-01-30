<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\ComponentDoesNotMeetRequirementsException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the ComponentStatus constraint.
 */
final class ComponentStatusConstraintValidator extends ConstraintValidator {

  use ComponentConfigEntityDependentValidatorTrait;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    \assert($constraint instanceof ComponentStatusConstraint);
    // Allow status = `false` even if Component doesn't meet requirements.
    if (!$value) {
      return;
    }
    $component = $this->createComponentConfigEntityFromContext();
    $source = self::getComponentSourceFromComponentIfPossible($component);
    if ($source === NULL) {
      return;
    }
    try {
      $source->checkRequirements();
    }
    catch (ComponentDoesNotMeetRequirementsException $exception) {
      $this->context->buildViolation($constraint->message, ['%component' => $component->id()])->addViolation();
      foreach ($exception->getMessages() as $message) {
        $this->context->addViolation($message);
      }
    }
  }

}
