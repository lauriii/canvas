<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

/**
 * An expression that evaluates to a concrete value: a scalar (one or a list).
 *
 * In the case of single cardinality:
 * - In PHP: string, int, float, bool
 * - In JSON Schema: string, number, integer, boolean.
 *
 * In the case of multiple cardinality: an array of scalars: a "list" array in
 * PHP terminology, "array" in JSON Schema.
 *
 * (That means this CANNOT be a reference prop expression nor an object prop
 * expression. This interface is mutually exclusive with both
 * ReferencePropExpressionInterface and ObjectPropExpressionInterface!)
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::isScalar()
 *
 * Evaluating this prop expression will produce a result that fits into a scalar
 * prop shape:
 *
 * @phpstan-type RequiredSingleCardinalityScalarResult bool|int|float|string|\Stringable
 * @phpstan-type OptionalSingleCardinalityScalarResult RequiredSingleCardinalityScalarResult|null
 * @phpstan-type RequiredMultipleCardinalityScalarResult non-empty-array<int, RequiredSingleCardinalityScalarResult>
 * @phpstan-type OptionalMultipleCardinalityScalarResult array<int, RequiredSingleCardinalityScalarResult>
 *
 * @internal
 */
interface ScalarPropExpressionInterface extends StructuredDataPropExpressionInterface {

  /**
   * Gets the name of the field property evaluated by this scalar expression.
   *
   * @return string
   *   A field property, such as `value`, `target_id`, `processed`, etc.
   */
  public function getFieldPropertyName(): string;

}
