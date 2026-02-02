<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\canvas\TypedData\BetterEntityDataDefinition;
use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * For pointing to a prop in a field type (not considering any delta).
 */
final class ReferenceFieldTypePropExpression implements FieldTypeBasedPropExpressionInterface, ReferencePropExpressionInterface {

  use CompoundExpressionTrait;

  public function __construct(
    public readonly FieldTypePropExpression $referencer,
    public readonly ReferencedBundleSpecificBranches|EntityFieldBasedPropExpressionInterface $referenced,
  ) {
    if ($this->needsMultiBundleReferencePropExpressionUpdate()) {
      // Note this cannot be an exception, because that would prevent the update
      // path from working.
      // @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
      @trigger_error('Creating ' . __CLASS__ . ' that contains references targeting multiple bundles is deprecated in canvas:1.0.5 and will be removed from canvas:2.0.0. Instead, create a ' . ReferenceFieldTypePropExpression::class . ', then use its ::withAdditionalBranch() to create multiple expression branches, each pointing to a single-bundle ' . __CLASS__ . '. See https://www.drupal.org/node/3563451', E_USER_DEPRECATED);
    }
  }

  /**
   * Detects deprecated multi-bundle expressions.
   *
   * If any of the object properties point to a multi-bundle reference, the
   * expression needs updating.
   *
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions()
   * @internal
   */
  public function needsMultiBundleReferencePropExpressionUpdate(): bool {
    return $this->referenced instanceof ReferenceFieldPropExpression && count($this->referenced->referencer->getHostEntityDataDefinition()->getBundles() ?? []) > 1;
  }

  public function __toString(): string {
    if (!$this->referenced instanceof ReferencedBundleSpecificBranches) {
      $referenced_as_string = self::withoutExpressionTypePrefix((string) $this->referenced);
    }
    else {
      $referenced_as_string = (string) $this->referenced;
    }

    return static::PREFIX_EXPRESSION_TYPE
      . self::withoutExpressionTypePrefix((string) $this->referencer)
      . self::PREFIX_ENTITY_LEVEL
      . $referenced_as_string;
  }

  /**
   * Adds bundle-specific expression branch.
   *
   * Convenience method for `hook_canvas_storable_prop_shape_alter()`
   * implementations.
   * Uses expression branches to expand CandidateStorablePropShape matches
   * without losing matches that are already present.
   * Expression branches can contain multiple entity type and bundle
   * combinations mapped a prop shape in a single expression.
   *
   * @param FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $branch_to_add
   *   The bundle-specific expression branch to add.
   *   For example, the "alt" field property on the referenced baby photo's
   *   media source field. This FieldPropExpression's string representation
   *   would be `ℹ︎␜entity:media:baby_photos␝field_media_1␞␟alt`.
   *
   * @return static
   *   A new (immutable) prop expression object instance.
   *   For example, if the current expression would already list a single media
   *   type's "alt" field property, then adding the second as in the example
   *   would result in the following ReferenceFieldTypePropExpression string
   *   representation: `ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]`.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   * @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches
   * @see canvas.api.php
   */
  public function withAdditionalBranch(FieldPropExpression|ReferenceFieldPropExpression|FieldObjectPropsExpression $branch_to_add): static {
    if ($this->referenced instanceof ReferencedBundleSpecificBranches) {
      // No need for a transition: already has multiple bundle-specific
      // expression branches.
      $bundle_specific_referenced_expressions = [
        ...$this->referenced->bundleSpecificReferencedExpressions,
        $branch_to_add->getHostEntityDataDefinition()->getDataType() => $branch_to_add,
      ];
    }
    else {
      // Transition to multiple bundle-specific expression branches.
      $bundle_specific_referenced_expressions = [
        $this->referenced->getHostEntityDataDefinition()->getDataType() => $this->referenced,
        $branch_to_add->getHostEntityDataDefinition()->getDataType() => $branch_to_add,
      ];
    }

    // Comply with the order requirements of ReferencedBundleSpecificBranches.
    ksort($bundle_specific_referenced_expressions);

    return new static($this->referencer, new ReferencedBundleSpecificBranches($bundle_specific_referenced_expressions));
  }

  /**
   * Checks presence of a bundle-specific expression branch.
   *
   * Convenience method for `hook_canvas_storable_prop_shape_alter()`
   * implementations. To be used together with ::targetsMultipleBundles().
   *
   * @param string $branch_to_check
   *   The key (a Typed Data type) of the bundle-specific expression branch to
   *   check the presence of.
   *   For example, `entity:media:baby_photos` or `entity:media:image`.
   *
   * @return bool
   *   For example, if the current expression's string representation is
   *   `ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]`,
   *   then checking for the presence of `entity:media:baby_photos` or
   *   `entity:media:vacation_photos` would both return TRUE, but checking for
   *   `entity:media:image` would return FALSE.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   * @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches
   * @see canvas.api.php
   */
  public function hasBranch(string $branch_to_check): bool {
    if (!$this->targetsMultipleBundles()) {
      \assert($this->referenced instanceof EntityFieldBasedPropExpressionInterface);
      return $this->referenced->getHostEntityDataDefinition()->getDataType() === $branch_to_check;
    }
    \assert($this->referenced instanceof ReferencedBundleSpecificBranches);
    return $this->referenced->hasBranch($branch_to_check);
  }

  /**
   * Removes bundle-specific expression branch.
   *
   * Convenience method for `hook_storage_prop_shape_alter()` implementations.
   *
   * @param string $branch_to_remove
   *   The key (a Typed Data type) of the bundle-specific expression branch to
   *   remove.
   *   For example, the "baby_photos" media type bundle-specific expression's
   *   branch can be removed by passing in `entity:media:baby_photos`, when the
   *   this expression is:
   *   `ℹ︎entity_reference␟entity␜[␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]`.
   *
   * @return static
   *   A new (immutable) prop expression object instance.
   *   For example, removing the "baby_photos" media type bundle-specific
   *   expression branch from the above example would result in the following
   *   ReferenceFieldTypePropExpression string representation:
   *   `ℹ︎entity_reference␟entity␜[␜entity:media:vacation_photos␝field_media_image_2␞␟entity␜␜entity:file␝uri␞␟value]`.
   *
   * @see \Drupal\canvas\Hook\ShapeMatchingHooks::mediaLibraryStorablePropShapeAlter()
   * @see \Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches
   * @see canvas.api.php
   */
  public function withoutBranch(string $branch_to_remove): static {
    if (!$this->targetsMultipleBundles()) {
      \assert($this->referenced instanceof EntityFieldBasedPropExpressionInterface);
      throw new \LogicException('Impossible to remove a branch if there are not multiple branches to begin with. Call ::targetsMultipleBundles() to check first.');
    }
    \assert($this->referenced instanceof ReferencedBundleSpecificBranches);
    $existing_branches = $this->referenced->bundleSpecificReferencedExpressions;
    if (!$this->hasBranch($branch_to_remove)) {
      throw new \InvalidArgumentException(\sprintf("The branch `%s` was not found. Existing branches: `%s`.", $branch_to_remove, implode('`, `', array_keys($existing_branches))));
    }

    unset($existing_branches[$branch_to_remove]);
    return new static($this->referencer, match (count($existing_branches)) {
      // Single expression branch.
      1 => reset($existing_branches),
      // Multiple $existing_branches branches.
      // @phpstan-ignore argument.type
      default => new ReferencedBundleSpecificBranches($existing_branches),
    });
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(FieldableEntityInterface|FieldItemListInterface|null $field_item_list = NULL): array {
    \assert($field_item_list === NULL || $field_item_list instanceof FieldItemListInterface);
    $dependencies = $this->referencer->calculateDependencies($field_item_list);
    if ($field_item_list === NULL) {
      $dependencies = NestedArray::mergeDeep($dependencies, $this->referenced->calculateDependencies());
    }
    else {
      // ⚠️ Do not require values while calculating dependencies: this MUST not
      // fail.
      $referenced_content_entities = Evaluator::evaluate($field_item_list, $this->referencer, is_required: FALSE)->value;
      $referenced_content_entities = match (gettype($referenced_content_entities)) {
        // Reference field containing nothing.
        'null' => [],
        // Reference field containing multiple references.
        'array' => $referenced_content_entities,
        // Reference field containing a single reference.
        default => [$referenced_content_entities],
      };
      \assert(Inspector::assertAllObjects($referenced_content_entities, FieldableEntityInterface::class));
      $dependencies['content'] = [
        ...$dependencies['content'] ?? [],
        ...array_map(
          fn (FieldableEntityInterface $entity) => $entity->getConfigDependencyName(),
          $referenced_content_entities,
        ),
      ];
      // The referenced content entity is the starting point for the
      // `referenced` expression, so pass it as the host entity. This is
      // necessary to ensure content dependencies in references are identified.
      foreach ($referenced_content_entities as $referenced_content_entity) {
        $dependencies = NestedArray::mergeDeep($dependencies, $this->referenced->calculateDependencies($referenced_content_entity));
      }
      if (empty($referenced_content_entities)) {
        $dependencies = NestedArray::mergeDeep($dependencies, $this->referenced->calculateDependencies());
      }
    }
    return $dependencies;
  }

  public static function fromString(string $representation): static {
    $is_branching = str_contains($representation, self::PREFIX_ENTITY_LEVEL . self::PREFIX_BRANCH . self::PREFIX_ENTITY_LEVEL);
    if ($is_branching) {
      // Find opening of first branch
      $opening_first_branch = mb_strpos($representation, self::PREFIX_BRANCH);
      \assert(is_int($opening_first_branch));
      // Find closing of last branch.
      $closing_last_branch = mb_strrpos($representation, self::SUFFIX_BRANCH);
      \assert(is_int($closing_last_branch));
      $branches = self::parseBranches(mb_substr($representation, $opening_first_branch, $closing_last_branch));
      $referenced_branches = array_map(
        // Each of the branch expressions MUST be starting with an entity field,
        // because that is the only way to branch. Therefore, parse each as its
        // own stand-alone prop expression.
        fn (string $branch) => StructuredDataPropExpression::fromString(static::PREFIX_EXPRESSION_TYPE . $branch),
        $branches
      );
      \assert(Inspector::assertAllObjects($referenced_branches, EntityFieldBasedPropExpressionInterface::class));
      $parts = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_BRANCH . self::PREFIX_ENTITY_LEVEL, $representation, 2);
      $referencer = FieldTypePropExpression::fromString($parts[0]);
      $referenced = new ReferencedBundleSpecificBranches(array_combine(
        array_map(
          fn (EntityFieldBasedPropExpressionInterface $expr) => $expr->getHostEntityDataDefinition()->getDataType(),
          $referenced_branches,
        ),
        $referenced_branches,
      ));
      return new static($referencer, $referenced);
    }

    $parts = explode(self::PREFIX_ENTITY_LEVEL . self::PREFIX_ENTITY_LEVEL, $representation, 2);
    $referencer = FieldTypePropExpression::fromString($parts[0]);
    $referenced = StructuredDataPropExpression::fromString(static::PREFIX_EXPRESSION_TYPE . static::PREFIX_ENTITY_LEVEL . $parts[1]);
    \assert($referenced instanceof EntityFieldBasedPropExpressionInterface);
    return new static($referencer, $referenced);
  }

  public function validateSupport(EntityInterface|FieldItemInterface|FieldItemListInterface $field): void {
    \assert($field instanceof FieldItemInterface || $field instanceof FieldItemListInterface);
    $actual_field_type = $field->getFieldDefinition()->getType();
    if ($actual_field_type !== $this->referencer->fieldType) {
      throw new \DomainException(\sprintf("`%s` is an expression for field type `%s`, but the provided field item (list) is of type `%s`.", (string) $this, $this->referencer->fieldType, $actual_field_type));
    }
  }

  /**
   * @todo Consider adding such helpers to all StructuredDataPropExpressionInterface implementations?
   * */
  public function getFieldDefinition(): FieldDefinitionInterface {
    if (!$this->referenced instanceof FieldPropExpression) {
      throw new \LogicException('Not supported.');
    }
    // @phpstan-ignore-next-line
    return $this->referenced->entityType
      // @phpstan-ignore-next-line
      ->getPropertyDefinition($this->referenced->fieldName);
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string {
    return $this->referencer->fieldType;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldPropertyName(): string {
    return $this->referencer->propName;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetExpression(FieldableEntityInterface|EntityDataDefinitionInterface|null $referenced = NULL) : EntityFieldBasedPropExpressionInterface {
    if ($this->targetsMultipleBundles() && $referenced === NULL) {
      throw new \LogicException('A reference expression that targets multiple bundles needs to be informed which branch to return.');
    }

    // Single-branch.
    if ($referenced === NULL) {
      \assert(!$this->referenced instanceof ReferencedBundleSpecificBranches);
      // @see ::withoutBranch()
      return $this->referenced;
    }

    // Multi-branch.
    \assert($this->referenced instanceof ReferencedBundleSpecificBranches);

    if ($referenced instanceof FieldableEntityInterface) {
      \assert($referenced->bundle() !== NULL);
      return $this->referenced->getBranch($referenced->getEntityTypeId(), $referenced->bundle());
    }

    if ($referenced->getEntityTypeId() === NULL) {
      throw new \LogicException('The referenced entity data definition must have an entity type ID defined when selecting a bundle-specific branch.');
    }
    $bundles = $referenced->getBundles() ?? [];
    if (count($bundles) !== 1) {
      throw new \LogicException('The referenced entity data definition must have a single bundle defined when selecting a bundle-specific branch.');
    }
    return $this->referenced->getBranch($referenced->getEntityTypeId(), reset($bundles));
  }

  /**
   * {@inheritdoc}
   */
  public function getFinalTargetExpression(): (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ObjectPropExpressionInterface&EntityFieldBasedPropExpressionInterface) {
    if ($this->referenced instanceof ReferencePropExpressionInterface) {
      return $this->referenced->getFinalTargetExpression();
    }

    \assert($this->referenced instanceof ScalarPropExpressionInterface || $this->referenced instanceof ObjectPropExpressionInterface);
    return $this->referenced;
  }

  /**
   * {@inheritdoc}
   */
  public function targetsMultipleBundles(): bool {
    // @see ::withoutBranch()
    return $this->referenced instanceof ReferencedBundleSpecificBranches;
  }

  /**
   * @see https://www.drupal.org/node/3563451
   * @see ::needsMultiBundleReferencePropExpressionUpdate()
   * @see \canvas_post_update_0011_multi_bundle_reference_prop_expressions())
   * @see \Drupal\canvas\PropExpressions\StructuredData\FieldTypeObjectPropsExpression::liftReferenceAndCreateBranchesIfNeeded()
   * @internal
   */
  public function generateBundleSpecificBranches(): ReferenceFieldTypePropExpression {
    // Automatically convert from a past poor deadline-driven decision to the
    // long-term viable approach:
    // - FROM: bundle-specific branches encoded into FieldPropExpression (which
    //   means following references only for a specific bundle is impossible!)
    // - TO: bundle-specific branches encoded into infrastructure without such
    //   limitations, and deprecating the old way, to make FieldPropExpression
    //   simple again.
    // @see https://www.drupal.org/node/3563451
    // Note that FieldObjectPropsExpression never had multi-bundle support: that
    // is why this can specifically check for FieldPropExpression only.
    $deprecated_multi_bundle_reference = $this->referenced;
    \assert($deprecated_multi_bundle_reference instanceof ReferenceFieldPropExpression);
    $deprecated_multi_bundle_field_prop_expression = $deprecated_multi_bundle_reference->referencer;
    $target_bundles = $deprecated_multi_bundle_field_prop_expression->getHostEntityDataDefinition()->getBundles();
    // @see ::needsMultiBundleReferencePropExpressionUpdate()
    if ($target_bundles === NULL || count($target_bundles) === 1) {
      throw new \LogicException(__METHOD__ . ' should only be called for multi-bundle reference field prop expressions.');
    }
    \assert(count($target_bundles) > 1);

    // TRICKY: deprecation is triggered in the FieldPropExpression.
    // @see \Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression::__construct()

    // Two things are consistent across all branches:
    // 1. the entity type
    // 2. the delta
    $entity_type_id = $deprecated_multi_bundle_field_prop_expression->entityType->getEntityTypeId();
    $delta = $deprecated_multi_bundle_field_prop_expression->delta;

    // The rest must be unbundled into branches. (Pun intended.)
    $branches = [];
    // Introduced by https://www.drupal.org/i/3530521. Mistake in hindsight.
    $multi_bundle = $target_bundles;
    // Introduced by https://www.drupal.org/i/3530521. Mistake in hindsight.
    $multi_field_name = $deprecated_multi_bundle_field_prop_expression->fieldName;
    // Introduced by https://www.drupal.org/i/3530533. Mistake in hindsight.
    $multi_prop_name = $deprecated_multi_bundle_field_prop_expression->propName;
    foreach ($multi_bundle as $bundle) {
      $field_name = is_string($multi_field_name) ? $multi_field_name : $multi_field_name[$bundle];
      $prop_name = is_string($multi_prop_name) ? $multi_prop_name : $multi_prop_name[$field_name];
      $entity_and_bundle = BetterEntityDataDefinition::create($entity_type_id, $bundle);
      $branches[$entity_and_bundle->getDataType()] = new ReferenceFieldPropExpression(
        referencer: new FieldPropExpression(
          $entity_and_bundle,
          $field_name,
          $delta,
          $prop_name,
        ),
        // Unchanged, just repeated for every branch.
        referenced: $deprecated_multi_bundle_reference->referenced,
      );
    }
    \assert(count($branches) > 1);
    return new ReferenceFieldTypePropExpression(
      // Unchanged.
      referencer: $this->referencer,
      // New multi-branch structure.
      referenced: new ReferencedBundleSpecificBranches($branches),
    );
  }

}
