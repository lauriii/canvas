<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\PropExpressions\StructuredData\FieldTypeBasedPropExpressionInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * A candidate storable prop shape: for hook_canvas_storable_prop_shape_alter().
 *
 * The difference with StorablePropShape: all alterable properties are:
 * - writable instead of read-only
 * - optional instead of required
 *
 * All factors impacting hook_canvas_storable_prop_shape_alter() implementations
 * to overwrite one of these values, MUST be added as cacheable dependencies, to
 * enable Canvas to know when Component config entities need updating.
 *
 * @see \Drupal\canvas\PropShape\StorablePropShape
 */
final class CandidateStorablePropShape implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  public function __construct(
    public readonly PropShape $shape,
    public ?FieldTypeBasedPropExpressionInterface $fieldTypeProp = NULL,
    public string|null $fieldWidget = NULL,
    public int|null $cardinality = NULL,
    public array|null $fieldStorageSettings = NULL,
    public array|null $fieldInstanceSettings = NULL,
  ) {}

  public static function fromStorablePropShape(StorablePropShape $immutable): CandidateStorablePropShape {
    return new CandidateStorablePropShape(
      $immutable->shape,
      $immutable->fieldTypeProp,
      $immutable->fieldWidget,
      $immutable->cardinality,
      $immutable->fieldStorageSettings,
      $immutable->fieldInstanceSettings,
    );
  }

  public function toStorablePropShape() : ?StorablePropShape {
    if ($this->fieldTypeProp === NULL) {
      return NULL;
    }

    // Note: this will result in a fatal PHP error if a
    // hook_canvas_storable_prop_shape_alter() implementation alters
    // incorrectly.
    // @phpstan-ignore-next-line
    return new StorablePropShape($this->shape, $this->fieldTypeProp, $this->fieldWidget, $this->cardinality, $this->fieldStorageSettings, $this->fieldInstanceSettings);
  }

}
