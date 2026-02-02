<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;

/**
 * A prop expression that points to another prop expression via a reference.
 *
 * (That means this CANNOT be a scalar nor object prop expression. This
 * interface is mutually exclusive with ScalarPropExpressionInterface and
 * ObjectPropExpressionInterface!)
 *
 * Evaluation results do NOT fit in prop shapes; they're intermediaries pointing
 * which require another prop expression to evaluate them into a value that fits
 * into (scalar or object) prop shapes:
 *
 * @phpstan-type EntityReferenceSingleCardinalityResult \Drupal\Core\Entity\EntityInterface|null
 * @phpstan-type EntityReferenceMultipleCardinalityResult array<int, \Drupal\Core\Entity\EntityInterface|null>
 *
 * @internal
 */
interface ReferencePropExpressionInterface extends StructuredDataPropExpressionInterface {

  /**
   * Gets the name of the field property evaluated by this reference expression.
   *
   * @return string
   *   A field property, such as `value`, `target_id`, `processed`, etc.
   */
  public function getFieldPropertyName(): string;

  /**
   * Gets the target prop expression: for the target entity of this reference.
   *
   * (This may or may not be the final reference in the chain.)
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|\Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface|null $referenced
   *   Optional for single-bundle target reference expressions.
   *   Required for multi-bundle target reference expressions: either the
   *   referenced (content) entity itself, or its data definition object. This
   *   is used to select the correct branch.
   *
   * @return \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface
   *   The prop expression for the target entity of this reference.
   *
   * @throws \LogicException
   *   Thrown when called on a multi-bundle target reference expression without
   *   providing the required referenced entity or its data definition object.
   *
   * @see ::targetsMultipleBundles()
   */
  public function getTargetExpression(FieldableEntityInterface|EntityDataDefinitionInterface|null $referenced = NULL) : EntityFieldBasedPropExpressionInterface;

  /**
   * Gets the final prop expression: a concrete value this ultimately points to.
   *
   * (It will traverse as many references as needed to find the final expression
   * which evaluates to a concrete value.)
   *
   * @return (\Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface)|(\Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface&\Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface)
   *   The final prop expression in the reference chain, which MUST evaluate to
   *   a concrete value (scalar or object).
   *   This also MUST be an entity field-rooted prop expression, because only
   *   only entity fields can be references.
   *
   * @internal
   * @todo Finalize this in https://www.drupal.org/project/canvas/issues/3563309, or drop it.
   */
  public function getFinalTargetExpression() : (ScalarPropExpressionInterface&EntityFieldBasedPropExpressionInterface)|(ObjectPropExpressionInterface&EntityFieldBasedPropExpressionInterface);

  /**
   * Whether this targets multiple bundles.
   *
   * @return bool
   *   Returns TRUE for entity reference fields that are configured to target
   *   multiple bundles, FALSE otherwise.
   */
  public function targetsMultipleBundles(): bool;

}
