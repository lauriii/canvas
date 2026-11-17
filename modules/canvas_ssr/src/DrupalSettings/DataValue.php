<?php

declare(strict_types=1);

namespace Drupal\canvas_ssr\DrupalSettings;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * One value of drupalSettings, with its cacheability.
 *
 * It's site or page data, or a graph's interface translations.
 *
 * Renderer adds the cacheability to the island's, so the island's cached
 * markup varies with the value.
 *
 * The value and cacheability are built on first use, so a provider can
 * offer every key cheaply, and only the ones a render uses are built.
 *
 * @see \Drupal\canvas_ssr\DrupalSettings\Provider\SiteDataProviderInterface
 * @see \Drupal\canvas_ssr\DrupalSettings\Provider\PageDataProviderInterface
 */
final class DataValue implements CacheableDependencyInterface {

  /**
   * The value, once built.
   */
  private mixed $value = NULL;

  /**
   * Whether $value has been built. The value itself may be NULL.
   */
  private bool $valueBuilt = FALSE;

  /**
   * The cacheability, once built.
   */
  private ?CacheableMetadata $cacheability = NULL;

  /**
   * Constructs a DataValue.
   *
   * @param \Closure(): mixed $valueBuilder
   *   Builds the value. It must encode to JSON.
   * @param \Closure(): \Drupal\Core\Cache\CacheableMetadata $cacheabilityBuilder
   *   Builds the value's cacheability.
   */
  public function __construct(
    private readonly \Closure $valueBuilder,
    private readonly \Closure $cacheabilityBuilder,
  ) {}

  /**
   * Returns the value.
   */
  public function getValue(): mixed {
    if (!$this->valueBuilt) {
      $this->value = ($this->valueBuilder)();
      $this->valueBuilt = TRUE;
    }

    return $this->value;
  }

  /**
   * Returns the value's cacheability.
   */
  private function cacheability(): CacheableMetadata {
    return $this->cacheability ??= ($this->cacheabilityBuilder)();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return $this->cacheability()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return $this->cacheability()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return $this->cacheability()->getCacheMaxAge();
  }

}
