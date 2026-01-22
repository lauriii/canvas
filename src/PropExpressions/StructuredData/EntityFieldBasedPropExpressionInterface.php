<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;

/**
 * An expression that has an entity field as the starting point.
 *
 * (By contrast, FieldTypeBasedPropExpressionInterface is for expressions that
 * have a field type as the starting point.)
 *
 * These are used to evaluate data from entity fields, such as:
 * - a node's title, owner, image, tags, etc.
 * - a user's name, email, profile picture, etc.
 *
 * Note: this interface does not allow retrieving the field property that will
 * be evaluated by this expression. For that, see ScalarPropExpressionInterface
 * and ReferencePropExpressionInterface, which both evaluate a single field
 * property. By contrast, ObjectPropExpressionInterface may evaluate multiple
 * field properties at once (e.g. src, width and height of an "image" field).
 *
 * @see \Drupal\canvas\PropSource\DynamicPropSource
 * @todo DynamicPropSource is being renamed to EntityFieldPropSource in https://www.drupal.org/project/canvas/issues/3555229
 *
 * @internal
 */
interface EntityFieldBasedPropExpressionInterface extends StructuredDataPropExpressionInterface {

  /**
   * Gets the entity type and bundle this expression can be evaluated against.
   */
  public function getHostEntityDataDefinition(): EntityDataDefinitionInterface;

  /**
   * Gets the name of the field that this expression can be evaluated against.
   *
   * @return string
   *   A field name, such as `title`, `owner`, `field_image`, `field_tags`, etc.
   */
  public function getFieldName(): string;

  /**
   * Optionally, a specific delta may be targeted by the expression.
   *
   * Note that if a non-NULL delta is specified, only a subset of the data
   * contained by the field would be returned when evaluating the expression!
   *
   * @return int|null
   *   The delta (0, 1, 2, 5, 99 …), or NULL if all values are targeted.
   */
  public function getDelta(): ?int;

  /**
   * Whether the starting point is the same as that of another expression.
   *
   * When comparing entity field-based prop expressions, it is important to know
   * whether they start from the same point, meaning they evaluate the same set
   * of data.
   * In Drupal Typed data terminology: whether they target the same field data:
   * the same field item list, or potentially even the same specific field item.
   *
   * @param \Drupal\canvas\PropExpressions\StructuredData\EntityFieldBasedPropExpressionInterface $other
   *   The other expression to compare with.
   *
   * @return bool
   */
  public function hasSameStartingPointAs(EntityFieldBasedPropExpressionInterface $other): bool;

}
