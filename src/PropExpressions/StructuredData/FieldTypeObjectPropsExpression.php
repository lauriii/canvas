<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
final class FieldTypeObjectPropsExpression implements FieldTypeBasedPropExpressionInterface, ObjectPropExpressionInterface {

  use CompoundExpressionTrait;

  /**
   * Constructs a new FieldTypeObjectPropsExpression.
   *
   * @param string $fieldType
   *   A field type.
   * @param non-empty-array<string, \Drupal\canvas\PropExpressions\StructuredData\FieldTypePropExpression|\Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression> $objectPropsToFieldTypeProps
   *   A mapping of prop names to non-object field type-based expressions.
   */
  public function __construct(
    public readonly string $fieldType,
    public readonly array $objectPropsToFieldTypeProps,
  ) {
    \assert(!empty($this->objectPropsToFieldTypeProps));
    \assert(Inspector::assertAllStrings(array_keys($this->objectPropsToFieldTypeProps)));
    \assert(Inspector::assertAll(function ($expr) {
      return $expr instanceof FieldTypePropExpression || $expr instanceof ReferenceFieldTypePropExpression;
    }, $this->objectPropsToFieldTypeProps));
    array_walk($objectPropsToFieldTypeProps, function (FieldTypeBasedPropExpressionInterface $expr) {
      $targets_same_field_type = $this->getFieldType() === $expr->getFieldType();
      if (!$targets_same_field_type) {
        throw new \InvalidArgumentException(\sprintf(
          '`%s` is not a valid expression, because it does not map the same field type (`%s`).',
          (string) $expr,
          $this->getFieldType(),
        ));
      }
    });

    // Detect multi-bundle references; that disambiguation belongs outside the
    // object shape's key-value mapping, each object shape must always deal with
    // only a single bundle at a time, to keep the expressions simple.
    // Only references are allowed to contain bundle disambiguation: using
    // bundle-specific expression branches.
    // @see \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::withAdditionalBranch()
    if ($this->needsMultiBundleReferencePropExpressionUpdate()) {
      // Note this cannot be an exception, because that would prevent the update
      // path from working.
      // @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
      @trigger_error('Creating ' . __CLASS__ . ' that contains references targeting multiple bundles is deprecated in canvas:1.0.5 and will be removed from canvas:2.0.0. Instead, create a ' . ReferenceFieldTypePropExpression::class . ', then use its ::withAdditionalBranch() to create multiple expression branches, each pointing to a single-bundle ' . __CLASS__ . '. See https://www.drupal.org/node/3563451', E_USER_DEPRECATED);
    }
    // Detect the edge case where all object properties point to the same
    // reference; that reference should be lifted outside the object shape.
    if ($this->needsLiftedReferencePropExpressionUpdate()) {
      // Note this cannot be an exception, because that would prevent the update
      // path from working.
      // @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
      @trigger_error('Creating ' . __CLASS__ . ' with the same reference for each object prop should is deprecated in canvas:1.0.5 and will be removed from canvas:2.0.0. Instead, create a ' . ReferenceFieldTypePropExpression::class . ' and point it to a ' . FieldObjectPropsExpression::class . '. See https://www.drupal.org/node/3563451', E_USER_DEPRECATED);
    }
  }

  /**
   * Detects the obvious case: multi-bundle expressions.
   *
   * If any of the object properties point to a multi-bundle reference, the
   * expression needs updating.
   *
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @internal
   */
  public function needsMultiBundleReferencePropExpressionUpdate(): bool {
    // Obvious case: if any of the object properties point to a multi-bundle
    // reference, the expression needs updating.
    foreach ($this->objectPropsToFieldTypeProps as $expr) {
      if ($expr instanceof ReferenceFieldTypePropExpression) {
        if ($expr->referenced instanceof FieldPropExpression
          && count($expr->referenced->getHostEntityDataDefinition()->getBundles() ?? []) > 1) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Detects the edge case: all object properties reference a single bundle.
   *
   * If all of the object properties point to a single-bundle reference, that
   * reference should be lifted outside the object expression.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @internal
   */
  public function needsLiftedReferencePropExpressionUpdate(): bool {
    $all_references_to_single_bundle = array_all(
      $this->objectPropsToFieldTypeProps,
      fn ($expr) => $expr instanceof ReferenceFieldTypePropExpression
        // Don't do this for the `file`, `image` and other field types that
        // subclass `entity_reference` but add additional field properties.
        && $expr->getFieldType() === 'entity_reference'
        && !$expr->referenced instanceof ReferencedBundleSpecificBranches
        && count($expr->referenced->getHostEntityDataDefinition()->getBundles() ?? []) === 1,
    );
    if (!$all_references_to_single_bundle) {
      return FALSE;
    }
    $unique_referenced_entity_type_and_bundles = array_map(
      // PHPStan fails the narrower types here as a result of the early return.
      // @phpstan-ignore argument.type, method.notFound
      fn (ReferenceFieldTypePropExpression $expr) => $expr->referenced->getHostEntityDataDefinition()->getDataType(),
      $this->objectPropsToFieldTypeProps,
    );

    // When all object keys are populated by values from references to the same
    // entity type and bundle(s), the reference expression must be lifted out of
    // the object shape.
    return count(array_unique($unique_referenced_entity_type_and_bundles)) === 1;
  }

  /**
   * @see https://www.drupal.org/node/3563451
   * @see ::needsMultiBundleReferencePropExpressionUpdate()
   * @see ::needsLiftedReferencePropExpressionUpdate()
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @see \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression::generateBundleSpecificBranches()
   * @internal
   */
  public function liftReferenceAndCreateBranchesIfNeeded(): ReferenceFieldTypePropExpression {
    if (!$this->needsLiftedReferencePropExpressionUpdate() && !$this->needsMultiBundleReferencePropExpressionUpdate()) {
      throw new \LogicException(__METHOD__ . ' should only be called when ::needsLiftedReferencePropExpressionUpdate() or ::needsLiftedReferencePropExpressionUpdate() returns TRUE.');
    }

    $first_object_key = \array_key_first($this->objectPropsToFieldTypeProps);
    $first_object_key_expr = $this->objectPropsToFieldTypeProps[$first_object_key];
    \assert($first_object_key_expr instanceof ReferenceFieldTypePropExpression);
    \assert(!$first_object_key_expr->referenced instanceof ReferencedBundleSpecificBranches);

    // This part will be lifted out.
    $reference_expression_field_type = $first_object_key_expr->referencer->fieldType;
    $reference_expression_prop_name = $first_object_key_expr->referencer->propName;

    // Two things are consistent across all branches:
    // 1. the entity type
    // 2. the delta
    $entity_type_id = $first_object_key_expr->referenced->getHostEntityDataDefinition()->getEntityTypeId();
    $delta = $first_object_key_expr->referenced->getDelta();

    // The rest must be unbundled into branches. (Pun intended.)
    $branches = [];
    // Introduced by https://www.drupal.org/i/3530521. Mistake in hindsight.
    $multi_bundle = $first_object_key_expr->referenced->getHostEntityDataDefinition()->getBundles();
    // TRICKY: the same update path is used to fix both multi-bundle references
    // inside object shapes, and also to lift references out of object shapes.
    \assert(is_array($multi_bundle) && count($multi_bundle) >= 1);
    // Introduced by https://www.drupal.org/i/3530521. Mistake in hindsight.
    \assert(!$first_object_key_expr->referenced instanceof ObjectPropExpressionInterface);
    \assert($first_object_key_expr->referenced instanceof FieldPropExpression || $first_object_key_expr->referenced instanceof ReferenceFieldPropExpression);
    $multi_field_name = match($first_object_key_expr->referenced::class) {
      FieldPropExpression::class => $first_object_key_expr->referenced->fieldName,
      ReferenceFieldPropExpression::class => $first_object_key_expr->referenced->referencer->fieldName,
    };

    foreach ($multi_bundle as $bundle) {
      $entity_type_and_bundle = BetterEntityDataDefinition::create($entity_type_id, $bundle);
      // Get the right field name for this branch (bundle), applies to all
      // expressions inside the object expression.
      $field_name = is_string($multi_field_name) ? $multi_field_name : $multi_field_name[$bundle];
      $object_props = [];
      foreach ($this->objectPropsToFieldTypeProps as $key => $obj_expr) {
        // Introduced by https://www.drupal.org/i/3530533. Mistake in hindsight.
        \assert($obj_expr instanceof ReferenceFieldTypePropExpression);
        \assert($obj_expr->referenced instanceof EntityFieldBasedPropExpressionInterface);
        \assert(!$obj_expr->referenced instanceof ObjectPropExpressionInterface);
        \assert($obj_expr->referenced instanceof FieldPropExpression || $obj_expr->referenced instanceof ReferenceFieldPropExpression);

        $multi_prop_name = match($obj_expr->referenced::class) {
          FieldPropExpression::class => $obj_expr->referenced->propName,
          ReferenceFieldPropExpression::class => $obj_expr->referenced->referencer->propName,
        };

        // Get the right prop name for this branch (bundle) and object key.
        $prop_name_for_bundle = is_string($multi_prop_name) ? $multi_prop_name : $multi_prop_name[$field_name];

        // An optional prop that does not exist on this bundle can simply be
        // omitted from the bundle-specific expression branch.
        if ($prop_name_for_bundle === StructuredDataPropExpressionInterface::SYMBOL_OBJECT_MAPPED_OPTIONAL_PROP) {
          continue;
        }

        $rewritten_expr = new FieldPropExpression(
          // The starting point is the same for all object props in this branch.
          // @see \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface::hasSameStartingPointAs()
          entityType: $entity_type_and_bundle,
          fieldName: $field_name,
          delta: $delta,
          propName: $prop_name_for_bundle
        );

        $object_props[$key] = match ($obj_expr->referenced::class) {
          // The object prop was a simple field prop expression: rewritten
          // thanks to the reference being lifted out of the object expression.
          FieldPropExpression::class => $rewritten_expr,
          ReferenceFieldPropExpression::class => new ReferenceFieldPropExpression(
            // Rewritten, similar to above, but inside a *subsequent* reference
            // expression.
            referencer: $rewritten_expr,
            // Unchanged.
            referenced: $obj_expr->referenced->referenced,
          ),
        };
      }
      \assert(count($object_props) >= 1);
      $branches[$entity_type_and_bundle->getDataType()] = new FieldObjectPropsExpression(
        $entity_type_and_bundle,
        $field_name,
        $delta,
        $object_props,
      );
    }

    \assert(count($branches) >= 1);
    return new ReferenceFieldTypePropExpression(
      referencer: new FieldTypePropExpression(
        fieldType: $reference_expression_field_type,
        propName: $reference_expression_prop_name,
      ),
      referenced: match (count($branches)) {
        1 => reset($branches),
        default => new ReferencedBundleSpecificBranches($branches),
      }
    );
  }

  public function __toString(): string {
    return static::PREFIX_EXPRESSION_TYPE
      . $this->fieldType
      . static::PREFIX_PROPERTY_LEVEL . static::PREFIX_OBJECT
      . implode(',', array_map(
        fn (string $obj_prop_name, FieldTypePropExpression|ReferenceFieldTypePropExpression $expr) => \sprintf('%s%s%s',
          $obj_prop_name,
          $expr instanceof ReferenceFieldTypePropExpression
            ? self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE
            : self::SYMBOL_OBJECT_MAPPED_USE_PROP,
          $expr instanceof ReferenceFieldTypePropExpression
            ? $expr->referencer->propName . self::PREFIX_ENTITY_LEVEL . self::withoutExpressionTypePrefix((string) $expr->referenced)
            : $expr->propName,
        ),
        array_keys($this->objectPropsToFieldTypeProps),
        array_values($this->objectPropsToFieldTypeProps),
      ))
      . static::SUFFIX_OBJECT;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $field_item_list = NULL): array {
    \assert($field_item_list === NULL || $field_item_list instanceof FieldItemListInterface);
    $dependencies = [];
    foreach ($this->objectPropsToFieldTypeProps as $expr) {
      $dependencies = NestedArray::mergeDeep($dependencies, $expr->calculateDependencies($field_item_list));
    }
    return $dependencies;
  }

  public static function fromString(string $representation): static {
    [$field_type, $object_mapping] = explode(self::PREFIX_PROPERTY_LEVEL, mb_substr($representation, 2), 2);
    // Strip the surrounding curly braces.
    $object_mapping = mb_substr($object_mapping, 1, -1);

    $objectPropsToFieldTypeProps = [];
    foreach (explode(',', $object_mapping) as $obj_prop_mapping) {
      if (str_contains($obj_prop_mapping, self::SYMBOL_OBJECT_MAPPED_USE_PROP)) {
        [$sdc_obj_prop_name, $field_type_prop_name] = explode(self::SYMBOL_OBJECT_MAPPED_USE_PROP, $obj_prop_mapping);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new FieldTypePropExpression($field_type, $field_type_prop_name);
      }
      else {
        [$sdc_obj_prop_name, $remainder] = explode(self::SYMBOL_OBJECT_MAPPED_FOLLOW_REFERENCE, $obj_prop_mapping);
        [$field_type_prop_name, $remainder] = explode(self::PREFIX_ENTITY_LEVEL, $remainder, 2);
        $referenced = StructuredDataPropExpression::fromString(static::PREFIX_EXPRESSION_TYPE . $remainder);
        \assert($referenced instanceof FieldPropExpression || $referenced instanceof ReferenceFieldPropExpression);
        $objectPropsToFieldTypeProps[$sdc_obj_prop_name] = new ReferenceFieldTypePropExpression(
          new FieldTypePropExpression($field_type, $field_type_prop_name),
          $referenced
        );
      }
    }

    return new static($field_type, $objectPropsToFieldTypeProps);
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $field): void {
    \assert($field instanceof FieldItemInterface || $field instanceof FieldItemListInterface);
    $actual_field_type = $field->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->fieldType) {
      throw new \DomainException(\sprintf("`%s` is an expression for field type `%s`, but the provided field item (list) is of type `%s`.", (string) $this, $this->fieldType, $actual_field_type));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string {
    return $this->fieldType;
  }

  /**
   * {@inheritdoc}
   *
   * @return non-empty-array<string, (\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface)|(\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface)>
   */
  public function getObjectExpressions(): array {
    return $this->objectPropsToFieldTypeProps;
  }

}
