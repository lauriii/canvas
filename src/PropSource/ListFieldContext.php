<?php

declare(strict_types=1);

namespace Drupal\canvas\PropSource;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * The iterated item's declared field values, for ListFieldPropSource.
 *
 * A repeating renderer (a query-results template rendering its tree once per
 * result row, the List element rendering its item template per item) declares
 * a set of named fields and produces their values per iteration. It pushes
 * those values around each iteration's render; ListFieldPropSource resolves
 * against the innermost frame during hydration.
 *
 * A stack rather than a single frame, so repeating renderers may nest (a list
 * inside a list's item template).
 *
 * @internal
 *
 * @see \Drupal\canvas\PropSource\ListFieldPropSource
 */
final class ListFieldContext {

  /**
   * @var list<array{values: array<string, string|null>, cacheability: \Drupal\Core\Cache\CacheableDependencyInterface}>
   */
  private array $stack = [];

  /**
   * Pushes one iteration's declared field values.
   *
   * @param array<string, string|null> $values
   *   The declared field values for this iteration, keyed by field name.
   *   String-valued per the provider contract; NULL for a field with no
   *   value this iteration.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface|null $cacheability
   *   Cacheability of the values (e.g. the query and the row's entity).
   */
  public function push(array $values, ?CacheableDependencyInterface $cacheability = NULL): void {
    $this->stack[] = [
      'values' => $values,
      'cacheability' => $cacheability ?? new CacheableMetadata(),
    ];
  }

  public function pop(): void {
    if ($this->stack === []) {
      throw new \LogicException('ListFieldContext::pop() without a matching push().');
    }
    \array_pop($this->stack);
  }

  public function hasContext(): bool {
    return $this->stack !== [];
  }

  /**
   * Gets the named field's value from the innermost iteration.
   */
  public function getValue(string $field_name): ?string {
    if ($this->stack === []) {
      return NULL;
    }
    return $this->stack[\array_key_last($this->stack)]['values'][$field_name] ?? NULL;
  }

  public function getCacheability(): CacheableDependencyInterface {
    if ($this->stack === []) {
      return new CacheableMetadata();
    }
    return $this->stack[\array_key_last($this->stack)]['cacheability'];
  }

}
