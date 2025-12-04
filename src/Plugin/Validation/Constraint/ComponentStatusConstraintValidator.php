<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
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
    assert($constraint instanceof ComponentStatusConstraint);
    // Allow status = `false` even if Component doesn't meet requirements.
    if (!$value) {
      return;
    }
    $component = $this->createComponentConfigEntityFromContext();

    // Requirements can only be checked if the source for the Component exists…
    try {
      $source = $component->getComponentSource();
    }
    catch (PluginNotFoundException) {
      // A validation error will be triggered for this by the `PluginExists`
      // constraint on the `source` key-value pair of the Component.
      return;
    }
    // … and if the underlying component is not broken.
    if ($source->isBroken()) {
      // A validation error will be triggered for this by the `PluginExists`
      // constraint on the `component` key-value pair.
      // @todo Remove this early return in
      //   https://www.drupal.org/project/drupal/issues/2820364. It is only
      //   necessary because this validator should run AFTER other validators
      //   (probably last), which means that this validator cannot assume it
      //   receives valid values.
      return;
    }
    try {
      $component->getComponentSource()->checkRequirements();
    }
    catch (ComponentDoesNotMeetRequirementsException $exception) {
      $this->context->buildViolation($constraint->message, ['%component' => $component->id()])->addViolation();
      foreach ($exception->getMessages() as $message) {
        $this->context->addViolation($message);
      }
    }
  }

}
