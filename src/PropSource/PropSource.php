<?php

declare(strict_types=1);

namespace Drupal\experience_builder\PropSource;

/**
 * @phpstan-import-type PropSourceArray from PropSourceBase
 * @phpstan-import-type AdaptedPropSourceArray from PropSourceBase
 * @phpstan-import-type UrlPreviewPropSourceArray from PropSourceBase
 */
final class PropSource {

  /**
   * @param PropSourceArray|AdaptedPropSourceArray|UrlPreviewPropSourceArray $prop_source
   */
  public static function parse(array $prop_source): PropSourceBase {
    $source_type_prefix = strstr($prop_source['sourceType'], PropSourceBase::SOURCE_TYPE_PREFIX_SEPARATOR, TRUE);
    // If the prefix separator is not present, then use the full source type.
    // For example: `dynamic` does not need a more detailed source type.
    // @see \Drupal\experience_builder\PropSource\DynamicPropSource::__toString()
    if ($source_type_prefix === FALSE) {
      $source_type_prefix = $prop_source['sourceType'];
    }

    // The UrlPreviewPropSource is the exceptional exception: it is never used
    // for storage, only for falling back to default values not expressible as
    // StaticPropSources during preview rendering.
    // @see \Drupal\experience_builder\Controller\ApiPreviewController
    // @see \Drupal\experience_builder\ComponentSource\UrlRewriteInterface
    if ($source_type_prefix === UrlPreviewPropSource::getSourceTypePrefix()) {
      return UrlPreviewPropSource::parse($prop_source);
    }

    // The AdaptedPropSource is the exception: it composes multiple other prop
    // sources, and those are listed under `adapterInputs`.
    if ($source_type_prefix === AdaptedPropSource::getSourceTypePrefix()) {
      assert(array_key_exists('adapterInputs', $prop_source));
      return AdaptedPropSource::parse($prop_source);
    }

    // All others PropSources are the norm: they each have an expression.
    assert(array_key_exists('expression', $prop_source));
    return match ($source_type_prefix) {
      StaticPropSource::getSourceTypePrefix() => StaticPropSource::parse($prop_source),
      DynamicPropSource::getSourceTypePrefix() => DynamicPropSource::parse($prop_source),
      default => throw new \LogicException('Unknown source type.'),
    };
  }

}
