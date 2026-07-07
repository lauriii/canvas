<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface;
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
 * Validates the EntityFieldExpressionMayOnlyTargetResolvableUris constraint.
 */
final class EntityFieldExpressionMayOnlyTargetResolvableUrisConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

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
    if (!$constraint instanceof EntityFieldExpressionMayOnlyTargetResolvableUrisConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionMayOnlyTargetResolvableUrisConstraint::class);
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

    $raw_uri = $this->findRawUriProperty($parsed);
    if ($raw_uri !== NULL) {
      [$field, $property] = $raw_uri;
      $this->context->addViolation($constraint->message, [
        '@field' => $field,
        '@property' => $property,
      ]);
    }
  }

  /**
   * Finds the first raw (non-resolvable) URI property in an expression.
   *
   * Walks reference chains and object-prop expressions: a `uri`-typed field
   * property is a raw URI unless it carries a UriSchemeConstraint restricted
   * to a subset of http/https, which guarantees it resolves to a
   * browser-accessible URL.
   *
   * @return array{0: string, 1: string}|null
   *   A [field, property] pair (e.g. ['entity:file.uri', 'value']) for the
   *   offending property, or NULL when none is a raw URI.
   *
   * @see \Drupal\Core\TypedData\Plugin\DataType\Uri
   * @see \Drupal\canvas\Utility\TypedDataHelper::isRestrictedToHttpSchemes()
   */
  private function findRawUriProperty(EntityFieldBasedPropExpressionInterface $expression): ?array {
    // Object-prop expression: every sub-property is on this same field.
    if ($expression instanceof ObjectPropExpressionInterface) {
      foreach ($expression->getObjectExpressions() as $sub_expression) {
        // PHPStan fails to conclude the sub-expression already is an
        // EntityFieldBasedPropExpressionInterface (same as in
        // PropSourceSuggester::isConsideredIrrelevant()).
        // @phpstan-ignore argument.type
        $found = $this->findRawUriProperty($sub_expression);
        if ($found !== NULL) {
          return $found;
        }
      }
      return NULL;
    }

    // Reference expression: the referencer's `entity` property is a
    // typed-data reference, not a `uri`-typed leaf; descend into the
    // referenced expression.
    // Multi-target-bundle references are rejected separately.
    // @see \Drupal\canvas\Plugin\Validation\Constraint\MultiTargetBundleReferenceNotSupportedConstraint
    if ($expression instanceof ReferencePropExpressionInterface) {
      // @todo Remove in https://git.drupalcode.org/project/canvas/-/work_items/3591656
      if ($expression instanceof ReferenceFieldPropExpression && $expression->targetsMultipleBundles()) {
        return NULL;
      }
      return $this->findRawUriProperty($expression->getTargetExpression());
    }

    // Scalar leaf: reject a raw `uri` property.
    if ($expression instanceof ScalarPropExpressionInterface) {
      $field_definition = $this->resolveFieldDefinition($expression);
      if ($field_definition === NULL) {
        return NULL;
      }
      $item_definition = $field_definition->getItemDefinition();
      if ($item_definition instanceof ComplexDataDefinitionInterface) {
        $property_definition = $item_definition->getPropertyDefinition($expression->getFieldPropertyName());
        if ($property_definition !== NULL
          && $property_definition->getDataType() === 'uri'
          && !TypedDataHelper::isRestrictedToHttpSchemes($property_definition)
        ) {
          $field_id = \sprintf('%s.%s', $expression->getHostEntityDataDefinition()->getDataType(), $expression->getFieldName());
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
