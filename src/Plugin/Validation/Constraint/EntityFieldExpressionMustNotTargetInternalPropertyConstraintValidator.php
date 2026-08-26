<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\canvas\Utility\TypedDataHelper;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the EntityFieldExpressionMustNotTargetInternalProperty constraint.
 */
final class EntityFieldExpressionMustNotTargetInternalPropertyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(EntityTypeManagerInterface::class),
      $container->get(EntityFieldManagerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExpressionMustNotTargetInternalPropertyConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionMustNotTargetInternalPropertyConstraint::class);
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

    $internal = $this->findInternalProperty($parsed);
    if ($internal !== NULL) {
      [$field, $property] = $internal;
      $this->context->addViolation($constraint->message, [
        '@field' => $field,
        '@property' => $property,
      ]);
    }
  }

  /**
   * Finds the first internal (non-computed) field property in an expression.
   *
   * Walks reference chains and object-prop expressions, matching the picker's
   * rule: a field property is excluded when it is internal but not computed,
   * or when it explicitly opts back in to being internal despite being
   * computed (e.g. `DateTimeItemOverride` marking `date` internal).
   * (Computed properties are internal-by-default in core yet remain pickable
   * otherwise.)
   *
   * @return array{0: string, 1: string}|null
   *   A [field, property] pair (e.g. ['entity:node:article.uid', 'pass']) for
   *   the offending property, or NULL when none is internal.
   *
   * @see \Drupal\canvas\Controller\ApiUiContentEntityReferenceControllers::buildFieldEntry()
   * @see \Drupal\canvas\Utility\TypedDataHelper::isEffectivelyInternal()
   */
  private function findInternalProperty(EntityFieldBasedPropExpressionInterface $expression): ?array {
    // Object-prop expression: every sub-property is on this same field.
    if ($expression instanceof ObjectPropExpressionInterface) {
      foreach ($expression->getObjectExpressions() as $sub_expression) {
        // PHPStan fails to conclude the sub-expression already is an
        // EntityFieldBasedPropExpressionInterface (same as in
        // PropSourceSuggester::isConsideredIrrelevant()).
        // @phpstan-ignore argument.type
        $found = $this->findInternalProperty($sub_expression);
        if ($found !== NULL) {
          return $found;
        }
      }
      return NULL;
    }

    // Reference expression: the referencer's `entity` property is computed (so
    // never internal-and-not-computed); descend into the referenced expression.
    // A multi-target-bundle reference has one branch per bundle; descend into
    // every branch, since an internal property in any branch is a violation.
    if ($expression instanceof ReferencePropExpressionInterface) {
      if ($expression instanceof ReferenceFieldPropExpression && $expression->targetsMultipleBundles()) {
        \assert($expression->referenced instanceof ReferencedBundleSpecificBranches);
        foreach ($expression->referenced->bundleSpecificReferencedExpressions as $branch_expression) {
          $found = $this->findInternalProperty($branch_expression);
          if ($found !== NULL) {
            return $found;
          }
        }
        return NULL;
      }
      return $this->findInternalProperty($expression->getTargetExpression());
    }

    // Scalar leaf: apply the picker's rule. A field property is excluded when
    // it is internal but not computed, or explicitly internal despite being
    // computed — at the field level (e.g. a base field marked internal) or
    // the field property level.
    if ($expression instanceof ScalarPropExpressionInterface) {
      $field_definition = $this->resolveFieldDefinition($expression);
      if ($field_definition === NULL) {
        return NULL;
      }
      $field_id = \sprintf('%s.%s', $expression->getHostEntityDataDefinition()->getDataType(), $expression->getFieldName());
      if (TypedDataHelper::isEffectivelyInternal($field_definition)) {
        return [$field_id, $expression->getFieldPropertyName()];
      }
      $item_definition = $field_definition->getItemDefinition();
      if ($item_definition instanceof ComplexDataDefinitionInterface) {
        $property_definition = $item_definition->getPropertyDefinition($expression->getFieldPropertyName());
        if ($property_definition !== NULL && TypedDataHelper::isEffectivelyInternal($property_definition)) {
          return [$field_id, $expression->getFieldPropertyName()];
        }
      }
    }

    return NULL;
  }

  /**
   * Resolves a scalar leaf expression to its field definition.
   */
  private function resolveFieldDefinition(ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface $expression): ?FieldDefinitionInterface {
    $host = $expression->getHostEntityDataDefinition();
    $entity_type_id = $host->getEntityTypeId();
    if ($entity_type_id === NULL || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      // Unknown entity type is handled by ExpressionTargetEntityBundleExists.
      return NULL;
    }
    $bundles = $host->getBundles();
    // Single-bundle or bundle-less (use the entity type ID as the bundle).
    $bundle = \is_array($bundles) && \count($bundles) === 1 ? \reset($bundles) : $entity_type_id;

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, (string) $bundle);
    $field_definition = $field_definitions[$expression->getFieldName()] ?? NULL;
    return $field_definition instanceof FieldDefinitionInterface ? $field_definition : NULL;
  }

}
