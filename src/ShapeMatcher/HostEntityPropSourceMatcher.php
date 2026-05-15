<?php

declare(strict_types=1);

namespace Drupal\canvas\ShapeMatcher;

use Drupal\canvas\PropShape\PropShape;
use Drupal\canvas\PropSource\HostEntityPropSource;

/**
 * Matcher for the host entity itself as a content-entity-reference prop source.
 *
 * @see \Drupal\canvas\PropSource\HostEntityPropSource
 * @see \Drupal\canvas\ShapeMatcher\EntityFieldPropSourceMatcher::matchContentEntityReferenceShape()
 *
 * @internal
 */
final class HostEntityPropSourceMatcher {

  /**
   * Matches a host entity prop source for a content-entity-reference prop.
   *
   * Returns a single HostEntityPropSource when the host entity satisfies the
   * prop's `x-allowed-entity-type-id` (strict equality) and either the prop's
   * `x-allowed-bundle` equals the host bundle, or the target entity type has
   * no bundles (the absence of `x-allowed-bundle` is upstream-enforced by
   * ComponentMetadataRequirementsChecker for bundled target entity types).
   *
   * @param bool $is_required
   *   Whether the prop shape to match is required or not.
   * @param \Drupal\canvas\PropShape\PropShape $prop_shape
   *   The prop shape to match.
   * @param string $host_entity_type
   *   The host entity type ID.
   * @param string $host_entity_bundle
   *   The host entity bundle.
   *
   * @return list<\Drupal\canvas\PropSource\HostEntityPropSource>
   *   A list with at most one HostEntityPropSource; empty when the host does
   *   not satisfy the prop's constraints (or the prop is not a
   *   content-entity-reference).
   */
  public static function match(bool $is_required, PropShape $prop_shape, string $host_entity_type, string $host_entity_bundle): array {
    $schema = $prop_shape->resolvedSchema;

    // Only content-entity-reference shapes are candidates: x-allowed-entity-
    // type-id is exclusively defined by the content-entity-reference JSON
    // Schema object ref and preserved through PropShape resolution.
    if (!\array_key_exists('x-allowed-entity-type-id', $schema)) {
      return [];
    }

    $target_type_id = $schema['x-allowed-entity-type-id'];
    \assert(\is_string($target_type_id));
    if ($target_type_id !== $host_entity_type) {
      return [];
    }

    // Bundle gate: when x-allowed-bundle is set it must equal the host bundle.
    // When absent, only valid for bundle-less target entity types (enforced
    // upstream by ComponentMetadataRequirementsChecker).
    $target_bundle = $schema['x-allowed-bundle'] ?? NULL;
    if ($target_bundle !== NULL && $target_bundle !== $host_entity_bundle) {
      return [];
    }

    return [new HostEntityPropSource()];
  }

}
