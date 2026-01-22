<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;

/**
 * A value object wrapping the result of evaluating a prop expression.
 *
 * @phpstan-import-type RequiredSingleCardinalityScalarResult from \Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface
 * @phpstan-import-type OptionalSingleCardinalityScalarResult from \Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface
 * @phpstan-import-type RequiredMultipleCardinalityScalarResult from \Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface
 * @phpstan-import-type OptionalMultipleCardinalityScalarResult from \Drupal\canvas\PropExpressions\StructuredData\ScalarPropExpressionInterface
 * @phpstan-import-type SingleCardinalityObjectResult from \Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface
 * @phpstan-import-type MultipleCardinalityObjectResult from \Drupal\canvas\PropExpressions\StructuredData\ObjectPropExpressionInterface
 * @phpstan-import-type EntityReferenceSingleCardinalityResult from \Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface
 * @phpstan-import-type EntityReferenceMultipleCardinalityResult from \Drupal\canvas\PropExpressions\StructuredData\ReferencePropExpressionInterface
 * @phpstan-type ActualEvaluationResult RequiredSingleCardinalityScalarResult|OptionalSingleCardinalityScalarResult|RequiredMultipleCardinalityScalarResult|OptionalMultipleCardinalityScalarResult|EntityReferenceSingleCardinalityResult|EntityReferenceMultipleCardinalityResult|SingleCardinalityObjectResult|MultipleCardinalityObjectResult
 *
 * The constructor may receive additional structures, which require hoisting.
 * @phpstan-type RawSingleCardinalityObjectEvaluationResult array<string, \Drupal\canvas\PropExpressions\StructuredData\EvaluationResult>
 */
final class EvaluationResult implements CacheableDependencyInterface {

  use CacheableDependencyTrait;

  /**
   * @var ActualEvaluationResult
   */
  public readonly mixed $value;

  /**
   * @param ActualEvaluationResult|\Drupal\canvas\PropExpressions\StructuredData\EvaluationResult|array<\Drupal\canvas\PropExpressions\StructuredData\EvaluationResult> $value
   *   The evaluation result.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $cacheability
   *   (optional) The cacheability metadata for the evaluation result.
   */
  public function __construct(
    mixed $value,
    CacheableDependencyInterface $cacheability = new CacheableMetadata(),
  ) {
    if (!$value instanceof self && !self::hasNestedInstances($value)) {
      \assert((is_array($value) && !static::hasNestedInstances($value)) || $value instanceof EntityInterface || is_string($value) || $value instanceof \Stringable || is_int($value) || is_float($value) || is_bool($value) || is_null($value));
      // @phpstan-ignore-next-line assign.propertyType
      $this->value = $value;
      $this->setCacheability($cacheability);
      return;
    }

    // The evaluation result itself is an EvaluationResult object: hoist the
    // value up and merge the cacheability.
    // This typically happens when additional cacheability is associated:
    // @code
    // $result = EvaluationResult(…);
    // $result_really_depends_on = new EvaluationResult($cacheability, $result);
    // @endcode
    if ($value instanceof self) {
      $this->value = $value->value;
      $this->setCacheability(
        CacheableMetadata::createFromObject($value)
          ->addCacheableDependency($cacheability)
      );
      return;
    }

    // Extra work necessary: hoist values out of nested EvaluationResults.
    // Happens for:
    // - object-shaped results, because each key-value pair in the object is
    //   populated (evaluated) individually, so: array<string, EvaluationResult>
    // - multiple-cardinality results: array<int, EvaluationResult>
    // To avoid burdening the callers of the EvaluationResult with hoisting
    // values out of nested EvaluationResult objects, handle it automatically.
    \assert(\is_array($value));
    $hoisted = self::hoistFromArray($value);
    \assert(\is_array($hoisted->value) && !static::hasNestedInstances($hoisted->value));
    $this->value = $hoisted->value;
    $this->setCacheability(
      CacheableMetadata::createFromObject($hoisted)
        ->addCacheableDependency($cacheability)
    );
  }

  /**
   * Ensures that no nested values are instances of this class.
   *
   * @param mixed $value
   *   The evaluation result value to assess.
   *
   * @return bool
   *   Whether the given value contains EvaluationResult instances.
   */
  private static function hasNestedInstances(mixed $value): bool {
    if (!is_array($value)) {
      return FALSE;
    }

    return array_any($value, fn ($v) => $v instanceof static || self::hasNestedInstances($v));
  }

  /**
   * Hoists nested evaluation results.
   *
   * @param array $value
   *   An array — either for a multiple-cardinality result or an object-shaped
   *   result — containing nested EvaluationResult instances that need to be
   *   hoisted.
   *
   * @return static
   *   An evaluation result with all nested EvaluationResults hoisted, and their
   *   cacheability combined.
   */
  private static function hoistFromArray(array $value) : static {
    \assert(self::hasNestedInstances($value));
    $combined_cacheability = new CacheableMetadata();
    $hoisted_values = [];
    foreach ($value as $k => $v) {
      // An evaluation result may contain an arbitrarily complex nested array,
      // with EvaluationResult objects deeply nested. Hoist them up.
      if (is_array($v) && self::hasNestedInstances($v)) {
        $v = self::hoistFromArray($v);
      }
      if (!$v instanceof EvaluationResult) {
        $hoisted_values[$k] = $v;
        continue;
      }
      $hoisted_values[$k] = $v->value;
      $combined_cacheability->addCacheableDependency($v);
    }
    return new EvaluationResult($hoisted_values, $combined_cacheability);
  }

}
