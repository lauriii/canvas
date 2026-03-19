<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\HostEntityUrlPropSource;

/**
 * Matcher for host entity URL prop sources.
 *
 * @see \Drupal\canvas\PropSource\HostEntityUrlPropSource
 *
 * @internal
 */
final class HostEntityUrlPropSourceMatcher {

  /**
   * Matches host entity URL prop sources for a given prop shape.
   *
   * @param bool $is_required
   *   Whether the prop shape to match is required or not.
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *   The prop shape to match.
   *
   * @return list<\Drupal\canvas\PropSource\HostEntityUrlPropSource>
   *   An array of HostEntityUrlPropSource instances, empty array if no matches.
   */
  public function match(bool $is_required, PropShape $prop_shape): array {
    if ($prop_shape->getType() !== JsonSchemaType::String) {
      return [];
    }

    $schema = $prop_shape->resolvedSchema;
    if (!\array_key_exists('format', $schema)) {
      return [];
    }

    $string_format = JsonSchemaStringFormat::tryFrom($schema['format']);
    if ($string_format === NULL) {
      return [];
    }

    $allowed_string_formats = [
      JsonSchemaStringFormat::Uri,
      JsonSchemaStringFormat::UriReference,
      JsonSchemaStringFormat::Iri,
      JsonSchemaStringFormat::IriReference,
    ];

    // HostEntityUrlPropSources can only populate URI prop shapes (and its
    // supersets).
    if (!\in_array($string_format, $allowed_string_formats, TRUE)) {
      return [];
    }
    $supports_only_absolute_urls = \in_array($string_format, [JsonSchemaStringFormat::Uri, JsonSchemaStringFormat::Iri], TRUE);

    // If an `x-allowed-schemes` shape restriction is present, and it doesn't
    // allow HTTP nor HTTPS, then no viable HostEntityUrlPropSource can exist.
    // @see \Drupal\canvas\Validation\JsonSchema\UriSchemeAwareFormatConstraint
    if (
      \array_key_exists('x-allowed-schemes', $schema)
      && empty(array_intersect($schema['x-allowed-schemes'], ['http', 'https']))
    ) {
      return [];
    }

    // If any `contentMediaType` shape restriction is present, then no viable
    // HostEntityUrlPropSource can exist (because these always point to
    // `text/html` resources).
    if (\array_key_exists('contentMediaType', $schema)) {
      return [];
    }

    $matches = [];
    // @todo Offer `canonical` vs `edit-form` vs … (and check whether the given entity type actually contains such a link template).
    $matches[] = new HostEntityUrlPropSource(absolute: $supports_only_absolute_urls);
    return $matches;
  }

}
