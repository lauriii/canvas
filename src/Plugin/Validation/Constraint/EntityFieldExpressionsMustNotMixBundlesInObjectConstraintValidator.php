<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Validation\Constraint;

use Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface;
use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the EntityFieldExpressionsMustNotMixBundlesInObject constraint.
 */
final class EntityFieldExpressionsMustNotMixBundlesInObjectConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof EntityFieldExpressionsMustNotMixBundlesInObjectConstraint) {
      throw new UnexpectedTypeException($constraint, EntityFieldExpressionsMustNotMixBundlesInObjectConstraint::class);
    }

    if (!\is_array($value)) {
      return;
    }

    foreach ($value as $expression_string) {
      if (!\is_string($expression_string)) {
        continue;
      }
      try {
        $parsed = StructuredDataPropExpression::fromString($expression_string);
      }
      catch (\Throwable) {
        // Invalid expressions are handled by ValidStructuredDataPropExpression.
        continue;
      }
      if (!$parsed instanceof EntityFieldBasedPropExpressionInterface) {
        continue;
      }
      if (self::mixesBundlesInObject($parsed)) {
        $this->context->addViolation($constraint->message, [
          '@field' => \sprintf(
            '%s.%s',
            $parsed->getHostEntityDataDefinition()->getDataType(),
            $parsed->getFieldName(),
          ),
        ]);
      }
    }
  }

  /**
   * Whether a reference object mixes bundle-specific picks across bundles.
   *
   * A `FieldObjectPropsExpression` reached through a multi-bundle reference is
   * unrenderable when any of its properties is a single-bundle reference (it
   * descends into one specific bundle) while the object's reference properties
   * cover more than one bundle: at render each property is evaluated against
   * the one resolved entity, and a single-bundle property throws for every
   * other bundle. Only a branch of per-bundle objects renders safely.
   */
  private static function mixesBundlesInObject(object $expr): bool {
    if ($expr instanceof FieldObjectPropsExpression) {
      $bundle_keys = [];
      $has_single_bundle_reference = FALSE;
      foreach ($expr->getObjectExpressions() as $property) {
        if ($property instanceof ReferenceFieldPropExpression) {
          if ($property->referenced instanceof ReferencedBundleSpecificBranches) {
            foreach (\array_keys($property->referenced->bundleSpecificReferencedExpressions) as $branch_key) {
              $bundle_keys[$branch_key] = TRUE;
            }
          }
          else {
            foreach (self::bundleKeys($property->referenced->getHostEntityDataDefinition()) as $bundle_key) {
              $bundle_keys[$bundle_key] = TRUE;
            }
            $has_single_bundle_reference = TRUE;
          }
        }
        // A property may itself be (or contain) a nested object.
        if (self::mixesBundlesInObject($property)) {
          return TRUE;
        }
      }
      if ($has_single_bundle_reference && \count($bundle_keys) > 1) {
        return TRUE;
      }
    }

    // Descend through references and branches to reach any nested objects.
    if ($expr instanceof ReferenceFieldPropExpression) {
      $referenced = $expr->referenced;
      if ($referenced instanceof ReferencedBundleSpecificBranches) {
        foreach ($referenced->bundleSpecificReferencedExpressions as $branch) {
          if (self::mixesBundlesInObject($branch)) {
            return TRUE;
          }
        }
      }
      elseif (self::mixesBundlesInObject($referenced)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The `entity:type[:bundle]` keys a host entity data definition covers.
   *
   * @return list<string>
   */
  private static function bundleKeys(EntityDataDefinitionInterface $definition): array {
    $entity_type_id = $definition->getEntityTypeId();
    if ($entity_type_id === NULL) {
      return [];
    }
    $bundles = $definition->getBundles();
    if ($bundles === NULL || $bundles === []) {
      return ["entity:$entity_type_id"];
    }
    return \array_map(static fn (string $bundle): string => "entity:$entity_type_id:$bundle", \array_values($bundles));
  }

}
