<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\PropSource\ObjectPropsSource;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * A storable custom object prop shape: composed per-sub-property shapes.
 *
 * The composite counterpart of StorablePropShape, for `type: object` prop
 * shapes with inline `properties` ("groups", possibly wrapped in
 * `type: array` + `items` for multi-value groups): there is no single field
 * type + widget, but one StorablePropShape per sub-property.
 *
 * @see \Drupal\canvas\PropShape\StorablePropShape
 * @see \Drupal\canvas\PropSource\ObjectPropsSource
 * @see docs/adr/0021-object-props-in-code-components.md
 * @internal
 */
final class ObjectPropsStorablePropShape {

  /**
   * @param \Drupal\canvas\PropShape\PropShape $shape
   *   The prop shape: the `type: object` shape itself, or the `type: array`
   *   shape whose items are the object shape (multi-value groups).
   * @param non-empty-array<string, \Drupal\canvas\PropShape\StorablePropShape> $subShapes
   *   One storable prop shape per sub-property, keyed by sub-property name.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED|int<2, max>|null $cardinality
   *   NULL for a single-value group; the maximum number of items (or
   *   CARDINALITY_UNLIMITED) for a multi-value group.
   */
  public function __construct(
    public readonly PropShape $shape,
    public readonly array $subShapes,
    public readonly ?int $cardinality = NULL,
  ) {
    \assert($this->subShapes !== []);
    if ($this->cardinality !== NULL && $this->cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && $this->cardinality < 2) {
      throw new \OutOfRangeException('Nonsensical cardinality for a multi-value group; use NULL for single-value groups.');
    }
  }

  public function toObjectPropsSource(): ObjectPropsSource {
    return ObjectPropsSource::generate(
      \array_map(
        static fn (StorablePropShape $sub_shape) => $sub_shape->toStaticPropSource(),
        $this->subShapes,
      ),
      $this->cardinality,
    );
  }

}
