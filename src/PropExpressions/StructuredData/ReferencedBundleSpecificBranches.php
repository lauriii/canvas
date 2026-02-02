<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * A "expression branches" container for referenced bundle-specific expressions.
 *
 * A single prop expression with multiple branches allows matching multiple
 * entity type and bundle combinations into a certain prop shape.
 *
 * This is especially useful for enabling contributed modules to expand a
 * (Canvas-provided) default set of bundle-specific matches with additional
 * matches using a simple mechanism: just add another branch to the existing
 * expression.
 *
 * For example: Canvas' Media Library support supports local images and videos
 * by default (via Drupal core's "image" and "video file" media source plugins).
 * Contributed modules that add more media source plugins (for example remote
 * images) can easily extend the existing bundle-specific expression branch set:
 * add more bundles (media types using other media source plugins) and specify
 * how to retrieve the desired structured data from those additional bundles.
 *
 * String representation encapsulates branches like so [branch1][branch1].
 * Example of a string representation with two branches:
 *
 * @code
 *  [␜entity:media:baby_photos␝field_media_image_1␞␟entity␜␜entity:file␝uri␞␟value][␜entity:media:remote_image␝field_media_test␞␟non_existent_computed_property]
 * @endcode
 * If storable prop shape matched to image prop of a component would contain
 * such an expression, then Canvas UI could load media entity browser that
 * allows selecting entities from baby_photos and remote_image bundles.
 *
 * @see \Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface
 * @internal
 */
final class ReferencedBundleSpecificBranches {

  use CompoundExpressionTrait;

  /**
   * Constructs a ReferencedBundleSpecificBranches object.
   *
   * Drupal's entity reference fields can target multiple bundles. Each bundle
   * may have its own set of fields and properties, so to retrieve a particular
   * shape of data from a reference field, we need to know which bundle the
   * referenced entity belongs to. This class allows specifying different
   * expressions for different bundles, enabling precise data retrieval based on
   * the bundle of the referenced entity.
   *
   * This value object enables ReferencePropExpressionInterface implementations
   * to support multi-bundle target reference expressions.
   *
   * @param non-empty-array<string, EntityFieldBasedPropExpressionInterface> $bundleSpecificReferencedExpressions
   *   An array of bundle-specific reference expressions that convey how to
   *   retrieve a particular shape of data from a reference field, with:
   *   - keys: the entity type + bundle(s), for example
   *     `entity:node:article`, `entity:media:image`, `entity:media:video`
   *   - values: an entity-field root expression: either a FieldPropExpression
   *     or a FieldObjectPropsExpression (for example for retrieving the URL of
   *     an image field), or even another ReferenceFieldPropExpression pointing
   *     to either of those (for example for retrieving a referenced media
   *     entity's image field)
   *   Keys must be sorted alphabetically to ensure a deterministic string
   *   representation
   *   All branch-specific expressions MUST consume the same data (presence or
   *   absence of deltas in the leaf expression) and evaluate to the same shape
   *   (same leaf expression class and field cardinality).
   *   These requirements ensure that the overall expression has predictable
   *   evaluation results, regardless of which branch is followed.
   */
  public function __construct(
    public readonly array $bundleSpecificReferencedExpressions,
  ) {
    // There is no point in using this intermediary object unless there's >=2
    // expression branches.
    if (count($this->bundleSpecificReferencedExpressions) < 2) {
      throw new \InvalidArgumentException('Inappropriate use of reference bundle-specific branches: only a single branch specified.');
    }

    // Each bundle-specific referenced expression MUST follow these rules:
    // 1. be one of the expression classes that can be used for branching
    // (only expression classes using entity-field as their roots make sense)
    // 2. actually be bundle-specific
    // (example: `File` entity type does not have bundles)
    // 3. target only one bundle per branch (which combined with the requirement
    // of being keyed by the Typed Data type for the entity+bundle ensures that
    // each branch is unambiguous)
    // 4. require predictable order (alphabetical)
    // 5. be consistent with the other branches: they must evaluate to the same
    // shape, which requires the same leaf expression class (A), field
    // cardinality (B) and absence or presence of a delta across all (C) — note
    // that the presence of a delta always results in a single value.
    $leaf_expression_classes = [];
    $leaf_cardinalities = [];
    $leaf_expression_has_deltas = [];
    foreach ($this->bundleSpecificReferencedExpressions as $entity_type_id_and_bundle => $expr) {
      // Validate rule 1: check if expression class is supported.
      if (!$expr instanceof EntityFieldBasedPropExpressionInterface) {
        throw new \InvalidArgumentException(\sprintf('`%s` is not a supported branch expression: an entity field-based prop expression must be given.', (string) $expr));
      }

      $expected_branch_key = $expr->getHostEntityDataDefinition()->getDataType();
      if ($entity_type_id_and_bundle !== $expected_branch_key) {
        throw new \InvalidArgumentException(\sprintf('`%s` is an incorrect key for the bundle-specific expression `%s`, expected `%s`.', (string) $entity_type_id_and_bundle, (string) $expr, $expected_branch_key));
      }

      // Validate rule 2: check if expression has a bundle.
      $target_bundles = $expr->getHostEntityDataDefinition()->getBundles();
      if ($target_bundles === NULL) {
        throw new \InvalidArgumentException(\sprintf('`%s` is not a bundle-specific reference expression.', (string) $expr));
      }
      // Validate rule 3: check if expression targets only one bundle.
      if (count($target_bundles) > 1) {
        throw new \InvalidArgumentException(\sprintf('`%s` targets multiple bundles: only a single bundle per branch is allowed.', (string) $expr));
      }

      // Gather information for cross-branch validation.
      $leaf = match (TRUE) {
        $expr instanceof ReferencePropExpressionInterface => $expr->getFinalTargetExpression(),
        default => $expr,
      };
      \assert($leaf instanceof ScalarPropExpressionInterface || $leaf instanceof ObjectPropExpressionInterface);
      $leaf_expression_classes[] = $leaf::class;
      $leaf_expression_has_deltas[] = $leaf->getDelta() !== NULL;
      // Do not validate cardinalities unless the entity type manager is
      // available.
      // @see \Drupal\Tests\canvas\Kernel\PropExpressionKernelTest::testInvalidReferenceFieldTypePropExpressionDueToMismatchedLeafExpressionCardinality
      // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
      if (\Drupal::getContainer()->has('entity_type.manager')) {
        $leaf_field_definition = $leaf->getHostEntityDataDefinition()
          ->getPropertyDefinition($leaf->getFieldName());
        \assert($leaf_field_definition instanceof FieldDefinitionInterface);
        $leaf_field_storage_definition = $leaf_field_definition->getFieldStorageDefinition();
        \assert($leaf_field_storage_definition instanceof FieldStorageDefinitionInterface);
        $leaf_cardinalities[] = $leaf_field_storage_definition->getCardinality();
      }
    }

    // Validate rule 4: check the order of branches.
    $expected_key_order = array_keys($this->bundleSpecificReferencedExpressions);
    sort($expected_key_order);
    if (array_keys($this->bundleSpecificReferencedExpressions) !== $expected_key_order) {
      throw new \InvalidArgumentException('Bundle-specific expressions are not in alphabetical order (by their keys).');
    }
    // Validate rule 5: ensure all branches evaluate to the same shape, which
    // requires them to have the same leaf expression class (A).
    if (count(array_unique($leaf_expression_classes)) > 1) {
      throw new \InvalidArgumentException('Bundle-specific expressions have inconsistent leaf expressions: they must all populate the same shape, and hence must use the same expression class for the leaf expression.');
    }
    // Validate rule 5: ensure all branches evaluate to the same shape, which
    // requires the fields to have the same cardinality (B).
    if (count(array_unique($leaf_cardinalities)) > 1) {
      throw new \InvalidArgumentException('Bundle-specific expressions have inconsistent leaf expressions: they must all must target fields of the same cardinality.');
    }
    // Validate rule 5: ensure all branches evaluate to the same shape, which
    // requires the same leaf expression delta presence/absence (C).
    if (count(array_unique($leaf_expression_has_deltas)) > 1) {
      throw new \InvalidArgumentException('Bundle-specific expressions have inconsistent leaf expressions: either all or none must specify a field delta.');
    }
  }

  public function __toString(): string {
    return array_reduce(
      $this->bundleSpecificReferencedExpressions,
      fn (string $carry, EntityFieldBasedPropExpressionInterface $expr)
        => $carry
          . StructuredDataPropExpressionInterface::PREFIX_BRANCH
          . self::withoutExpressionTypePrefix((string) $expr)
          . StructuredDataPropExpressionInterface::SUFFIX_BRANCH,
      '',
    );
  }

  /**
   * Checks presence of a bundle-specific expression branch.
   *
   * @param string $branch_to_check
   *   The key (a Typed Data type) of the bundle-specific expression branch to
   *   check the presence of.
   *
   * @return bool
   */
  public function hasBranch(string $branch_to_check): bool {
    $existing_branches = $this->bundleSpecificReferencedExpressions;
    return array_key_exists($branch_to_check, $existing_branches);
  }

  /**
   * @internal
   */
  public function getBranch(string $entity_type_id, string $bundle): EntityFieldBasedPropExpressionInterface {
    $data_type_id = "entity:$entity_type_id:$bundle";
    if (!array_key_exists($data_type_id, $this->bundleSpecificReferencedExpressions)) {
      throw new \OutOfRangeException(\sprintf("No branch found for entity type '%s' and bundle '%s'.", $entity_type_id, $bundle));
    }
    return $this->bundleSpecificReferencedExpressions[$data_type_id];
  }

  public function calculateDependencies(FieldableEntityInterface|null $host_entity = NULL): array {
    // If a host entity is given, also include content dependencies for the
    // specific branch that is for that entity.
    $deps = [];
    if ($host_entity !== NULL) {
      $branch_for_host_entity = $this->getBranch($host_entity->getEntityTypeId(), $host_entity->bundle());
      $deps[$branch_for_host_entity->getHostEntityDataDefinition()->getDataType()] = $branch_for_host_entity->calculateDependencies($host_entity);
    }

    // Calculate dependencies for all other branches.
    foreach ($this->bundleSpecificReferencedExpressions as $branch_key => $expr) {
      if (!array_key_exists($branch_key, $deps)) {
        $deps[$branch_key] = $expr->calculateDependencies();
      }
    }

    // Sort by branch keys to ensure predictable calculated dependency order.
    ksort($deps);
    return NestedArray::mergeDeepArray($deps);
  }

}
