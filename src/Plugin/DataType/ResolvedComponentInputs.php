<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\TypedData;
use Drupal\canvas\MissingHostEntityException;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropExpressions\StructuredData\EvaluationResult;

/**
 * A computed field property for a component instance's resolved inputs.
 *
 * Resolves stored prop sources (e.g., entity IDs) to their output values
 * (e.g., image URLs), making them available to normalizers (JSON:API, REST).
 *
 * @see docs/components.md#3.2.1
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs
 * @see \Drupal\text\TextProcessed
 */
final class ResolvedComponentInputs extends TypedData implements CacheableDependencyInterface {

  use ComputedDataTypeWithCacheabilityTrait;

  private ?array $computedValue = NULL;

  /**
   * {@inheritdoc}
   */
  private function computeValue(): ?array {
    \assert($this->isComputed === FALSE);
    $this->cacheability = new CacheableMetadata();

    $item = $this->getParent();
    \assert($item instanceof ComponentTreeItem);

    $component = $item->getComponent();
    if ($component === NULL) {
      return NULL;
    }

    $source = $component->getComponentSource();
    if (!$source->requiresExplicitInput()) {
      return [];
    }

    // This retrieves the resolved data in the `inputs` field property.
    // @see \Drupal\canvas\Plugin\DataType\ComponentInputs
    try {
      $hydrated_values = $source->getResolvedExplicitInput($item->getUuid(), $item);
    }
    catch (MissingHostEntityException) {
      // ContentTemplate component trees may contain prop sources that require
      // a fieldable host entity to be resolved. When accessed without one
      // (e.g. via JSON:API/REST normalization), resolution is not possible.
      // @see \Drupal\canvas\PropSource\EntityFieldPropSource::evaluate()
      // @see \Drupal\canvas\PropSource\HostEntityUrlPropSource::evaluate()
      return NULL;
    }

    // This computed field property is is intended to return an array with
    // explicit input names as string keys, and explicit input values as PHP
    // primitives (scalar values and arrays, not objects).
    $result = [];
    foreach ($hydrated_values as $name => $value) {
      // A component source using PropSources returns EvaluationResults.
      // @see \Drupal\canvas\PropSource\PropSourceBase::evaluate()
      if ($value instanceof EvaluationResult) {
        $result[$name] = $value->value;
        $this->cacheability->addCacheableDependency($value);
      }
      else {
        \assert(!\is_object($value), 'A Canvas ComponentSource plugin that wraps explicit/hydrated inputs using another class than EvaluationResult to associate cacheability with explicit input values is not yet supported. Please open an issue in the Canvas issue queue.');
        $result[$name] = $value;
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

}
