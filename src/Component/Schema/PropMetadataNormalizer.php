<?php

declare(strict_types=1);

namespace Drupal\canvas\Component\Schema;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * Normalizes prop metadata for consumers.
 *
 * @internal
 */
final class PropMetadataNormalizer {

  /**
   * Constructs a PropMetadataNormalizer.
   *
   * @param \Drupal\canvas\Component\Schema\PropChoiceOptionsResolver $choiceOptionsResolver
   *   The choice options resolver.
   */
  public function __construct(
    private readonly PropChoiceOptionsResolver $choiceOptionsResolver,
  ) {
  }

  /**
   * Adds normalized metadata to a prop.
   *
   * @param array $prop_metadata
   *   Baseline prop metadata array.
   * @param array $prop_schema
   *   Prop schema fragment.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Optional cacheability metadata to bubble.
   *
   * @return array
   *   Normalized prop metadata.
   */
  public function normalize(array $prop_metadata, array $prop_schema, ?CacheableMetadata $cacheability = NULL): array {
    $options = $this->choiceOptionsResolver->resolveEnumOptions($prop_schema, $cacheability);
    if ($options !== []) {
      $prop_metadata['enum_options'] = $options;
    }
    return $prop_metadata;
  }

}
