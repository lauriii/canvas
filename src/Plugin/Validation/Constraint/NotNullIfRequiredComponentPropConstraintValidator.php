<?php

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the given value is not NULL if the component prop is required.
 */
final class NotNullIfRequiredComponentPropConstraintValidator extends ConstraintValidator {

  use ComponentConfigEntityDependentValidatorTrait;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $mapping, Constraint $constraint): void {
    if (!$constraint instanceof NotNullIfRequiredComponentPropConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\NotNullIfRequiredComponentPropConstraint');
    }

    $component = $this->createComponentConfigEntityFromContext();
    $source = self::getComponentSourceFromComponentIfPossible($component);
    if ($source === NULL) {
      return;
    }
    \assert($source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    $component_schema = $source->getMetadata()->schema;

    /** @phpstan-ignore method.nonObject */
    $context_parent = $this->context->getObject()->getParent();
    $component_prop_name = $context_parent->getName();
    $is_required_component_prop = $context_parent->getValue()['required'];

    if ($is_required_component_prop && $mapping === NULL) {
      $this->context->buildViolation($constraint->message)
        // `title` is guaranteed to exist.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::checkRequirements()
        /** @phpstan-ignore offsetAccess.notFound */
        ->setParameter('%prop_title', $component_schema['properties'][$component_prop_name]['title'])
        ->setParameter('%prop_machine_name', $component_prop_name)
        ->addViolation();
    }
  }

}
