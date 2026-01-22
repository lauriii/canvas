<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

/**
 * An expression that evaluates to a concrete value: an object (one or a list).
 *
 * In the case of single cardinality:
 * - In PHP: associative array containing scalars in PHP (i.e. not a
 *   multi-dimensional array)
 * - In JSON Schema: an object (with properties of scalar types, not objects nor
 *   arrays).
 *
 * In the case of multiple cardinality: an array of objects: a "list" array in
 * PHP terminology, "array" in JSON Schema.
 *
 * (That means this CANNOT be a reference prop expression nor a scalar prop
 * expression. This interface is mutually exclusive with both
 * ReferencePropExpressionInterface and ScalarPropExpressionInterface!)
 *
 * Evaluation results fit in object prop shapes:
 *
 * @phpstan-import-type RequiredSingleCardinalityScalarResult from \Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface
 * @phpstan-type SingleCardinalityObjectResult array<string, RequiredSingleCardinalityScalarResult>
 * @phpstan-type MultipleCardinalityObjectResult array<int, SingleCardinalityObjectResult>
 *
 * @internal
 */
interface ObjectPropExpressionInterface extends StructuredDataPropExpressionInterface {

  /**
   * Gets the prop expression for each object key.
   *
   * @return non-empty-array<string, \Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface|\Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface>
   *   A mapping of object property names to scalar or reference prop
   *   expressions (nested objects are not supported).
   *   For example, the `ℹ︎image␟{src↝entity␜␜entity:file␝uri␞␟url,alt↠alt}`
   *   object expression, declares it can evaluate an "image" field type's field
   *   properties into two key-value pairs:
   *   - "src" mapped to a reference prop expression pointing to a file entity's
   *     "uri" field property
   *   - "alt" mapped to a scalar prop expression pointing to the field's "alt"
   *     field property
   *   For these, the getObjectExpressions() method would return:
   *   @code
   *   [
   *    'src' => 'ℹ︎image␟entity␜␜entity:file␝uri␞␟url',
   *    'alt' => 'ℹ︎image␟alt',
   *   ]
   *   @endcode
   */
  public function getObjectExpressions(): array;

}
