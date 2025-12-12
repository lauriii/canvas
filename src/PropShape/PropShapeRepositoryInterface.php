<?php

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

}
