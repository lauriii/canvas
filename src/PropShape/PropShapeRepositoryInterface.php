<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

/**
 * @internal
 */
interface PropShapeRepositoryInterface {

  /**
   * The set of unique prop shapes.
   *
   * @return array<string, \Drupal\canvas\PropShape\PropShape>
   *   The unique prop shapes, in a consistent order.
   *
   * @see \Drupal\canvas\PropShape\PropShape::uniquePropSchemaKey()
   */
  public function getUniquePropShapes(): array;

  /**
   * Gets the storable prop shape for a given prop shape.
   *
   * Takes a prop shape that is wraps a JSON Schema definition and translates it
   * into a storable prop shape that represents a field item and/or expression
   * representation that Drupal can store.
   *
   * @param \Drupal\canvas\PropShape\PropShape $shape
   *   The prop shape we wish to store.
   *
   * @return \Drupal\canvas\PropShape\StorablePropShape|null
   *   A storable prop shape, if one can be calculated.
   */
  public function getStorablePropShape(PropShape $shape): ?StorablePropShape;

  /**
   * Re-resolves the prop shapes queued by cache tag invalidations.
   *
   * When a cache tag is invalidated, its prop shapes are not re-resolved right
   * away: the config the tag describes is often still incomplete at that
   * moment. They are re-resolved later instead — at the latest when the
   * repository is destructed, but any code about to read storable prop shapes
   * whose correctness matters (e.g. to compute Component version hashes) must
   * call this first.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentDiscoveryBase::getPropsForComponentPlugin()
   */
  public function resolveInvalidatedPropShapes(): void;

}
