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
   * @return \Drupal\canvas\PropShape\StorablePropShape|\Drupal\canvas\PropShape\ObjectPropsStorablePropShape|null
   *   A storable prop shape, if one can be calculated. For custom object
   *   shapes ("groups"): a composite of per-sub-property storable shapes.
   */
  public function getStorablePropShape(PropShape $shape): StorablePropShape|ObjectPropsStorablePropShape|null;

  /**
   * Gets the storable prop shape for a sub-property of a custom object shape.
   *
   * Same as ::getStorablePropShape(), but the shape is an implementation
   * detail of a composed custom object shape ("group"), not a component prop
   * shape, so it is not registered in the unique prop shape discovery.
   *
   * @param \Drupal\canvas\PropShape\PropShape $shape
   *   The prop shape of a sub-property.
   *
   * @return \Drupal\canvas\PropShape\StorablePropShape|\Drupal\canvas\PropShape\ObjectPropsStorablePropShape|null
   *   A storable prop shape, if one can be calculated.
   *
   * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeObjectPropsStorablePropShape()
   */
  public function getStorablePropShapeForSubProperty(PropShape $shape): StorablePropShape|ObjectPropsStorablePropShape|null;

}
