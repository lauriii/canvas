<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\ContentTemplateRepository;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Validates the ContentTemplateEntityType constraint.
 */
final class ContentTemplateEntityTypeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ContentTemplateEntityTypeConstraint) {
      throw new UnexpectedTypeException($constraint, ContentTemplateEntityTypeConstraint::class);
    }

    if ($value === NULL) {
      return;
    }

    if (!\is_string($value)) {
      throw new UnexpectedValueException($value, 'string');
    }

    // Non-existent and non-content entity types are already caught by the
    // PluginExists constraint in the schema. Only add a violation here for
    // content entity types that exist but lack field_ui_base_route.
    $entity_type = $this->entityTypeManager->getDefinition($value, FALSE);
    if (!$entity_type instanceof ContentEntityTypeInterface) {
      return;
    }

    if (!ContentTemplateRepository::isSupportedEntityType($entity_type)) {
      $this->context->buildViolation($constraint->message)
        ->setInvalidValue($value)
        ->addViolation();
    }
  }

}
