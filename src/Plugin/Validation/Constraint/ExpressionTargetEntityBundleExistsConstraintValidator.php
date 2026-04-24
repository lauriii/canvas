<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the ExpressionTargetEntityBundleExists constraint.
 */
final class ExpressionTargetEntityBundleExistsConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
      $container->get(EntityTypeBundleInfoInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof ExpressionTargetEntityBundleExistsConstraint) {
      throw new UnexpectedTypeException($constraint, ExpressionTargetEntityBundleExistsConstraint::class);
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

    if (!$parsed instanceof EntityFieldBasedPropExpressionInterface) {
      return;
    }

    $entity_data_definition = $parsed->getHostEntityDataDefinition();
    $entity_type_id = $entity_data_definition->getEntityTypeId();

    if ($entity_type_id === NULL || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      $this->context->addViolation($constraint->entityTypeNotFoundMessage, [
        '@entity_type' => $entity_type_id ?? '(unknown)',
      ]);
      return;
    }

    $bundles = $entity_data_definition->getBundles();
    if ($bundles !== NULL) {
      $known_bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
      foreach ($bundles as $bundle) {
        if (!isset($known_bundles[$bundle])) {
          $this->context->addViolation($constraint->bundleNotFoundMessage, [
            '@entity_type' => $entity_type_id,
            '@bundle' => $bundle,
          ]);
        }
      }
    }
  }

}
