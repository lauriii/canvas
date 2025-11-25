<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Simplifies implementing DataType plugins that are cacheable dependencies.
 *
 * A TypedDataInterface implementation that uses this, must:
 * - declare a private NULL-by-default `$computedValue` property
 * - implement a private `computeValue()` method that populates both
 *   `$computedValue` and `$cacheability`
 *
 * Optionally, it can also override the `getValue()` implementation to provide
 * a more specific return type.
 *
 * @see \Drupal\Core\TypedData\TypedDataInterface
 * @see \Drupal\Core\Cache\CacheableDependencyInterface
 * @internal
 */
trait ComputedDataTypeWithCacheabilityTrait {

  /**
   * The cacheability of the computed value.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  private CacheableMetadata $cacheability;

  private bool $isComputed = FALSE;

  /**
   * Computes the value to be returned by TypedDataInterface::getValue().
   *
   * @return mixed
   */
  abstract private function computeValue();

  private function computeIfNeeded(): self {
    if (!$this->isComputed) {
      // @phpstan-ignore method.void
      $this->computedValue = $this->computeValue();

      // Not setting cacheability is considered failure. Either the computed
      // value itself must carry cacheability, otherwise `::computeValue()` must
      // explicitly populate $this->cacheability.
      // @phpstan-ignore-next-line instanceof.alwaysFalse instanceof.alwaysTrue
      if ($this->computedValue instanceof CacheableDependencyInterface) {
        $this->cacheability = CacheableMetadata::createFromObject($this->computedValue);
      }
      // @phpstan-ignore identical.alwaysFalse
      elseif ($this->cacheability === NULL) {
        throw new \LogicException('::computeValue() must set cacheability.');
      }
      // Do not compute again.
      $this->isComputed = TRUE;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(): mixed {
    return $this->computeIfNeeded()->computedValue;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->computeIfNeeded()->cacheability->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->computeIfNeeded()->cacheability->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->computeIfNeeded()->cacheability->getCacheMaxAge();
  }

}
