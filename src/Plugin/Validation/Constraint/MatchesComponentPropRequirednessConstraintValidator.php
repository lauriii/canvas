<?php

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the given boolean matches the requiredness of the component prop.
 */
final class MatchesComponentPropRequirednessConstraintValidator extends ConstraintValidator {

  use ComponentConfigEntityDependentValidatorTrait;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof MatchesComponentPropRequirednessConstraint) {
      throw new UnexpectedTypeException($constraint, __NAMESPACE__ . '\MatchesComponentPropRequirednessConstraint');
    }

    if (!is_bool($value)) {
      throw new UnexpectedValueException($value, 'bool');
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
    $prop_name = $context_parent->getName();
    $expected = in_array($prop_name, $component_schema['required'] ?? [], TRUE);

    if ($value !== $expected) {
      $this->context->buildViolation($constraint->message)
        // `title` is guaranteed to exist.
        // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponentDiscovery::checkRequirements()
        /** @phpstan-ignore offsetAccess.notFound */
        ->setParameter('%prop_title', $component_schema['properties'][$prop_name]['title'])
        ->setParameter('%prop_machine_name', $prop_name)
        ->addViolation();
    }
  }

}
