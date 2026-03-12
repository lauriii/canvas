<?php

declare(strict_types=1);

namespace Drupal\canvas\PropExpressions\StructuredData;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Analyses for a field item's data definition.
 *
 * @internal
 */
final class FieldItemAnalyzer {

  /**
   * Checks if a field item has >=1 property relying on an entity reference.
   *
   * Some computed field properties may themselves rely on prop expressions to
   * perform the computing in a parametrized way.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $data_definition
   *   A field item data definition.
   *
   * @return \Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression|null
   *   The first reference field type prop expression found, or NULL if there
   *   are none.
   *
   * @see \Drupal\canvas\Plugin\DataType\ComputedUrlWithQueryString
   */
  public static function getReferenceDependency(DataDefinitionInterface $data_definition): ?ReferenceFieldTypePropExpression {
    \assert(!str_starts_with($data_definition->getDataType(), 'field_item:'));

    if (!$data_definition->isReadOnly() && is_a($data_definition->getClass(), DependentPluginInterface::class, TRUE)) {
      return NULL;
    }

    // Find StructuredDataPropExpressions in the property's settings.
    $settings = $data_definition->getSettings();
    $found_expressions = [];
    array_walk_recursive($settings, function ($current) use (&$found_expressions) {
      if (\is_string($current) && StructuredDataPropExpression::isA($current)) {
        $found_expressions[] = $current;
      }
    });

    // Check if >=1 relies on an entity reference.
    foreach ($found_expressions as $found_expression) {
      $expression = StructuredDataPropExpression::fromString($found_expression);
      if ($expression instanceof ReferenceFieldTypePropExpression) {
        return $expression;
      }
    }

    return NULL;
  }

}
