<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * To be used when validating and discovering eligible components.
 *
 * @internal
 */
class EphemeralPropShapeRepository implements PropShapeRepositoryInterface {

  /**
   * Unique prop shapes seen during the lifetime of this service.
   *
   * @var array<string, \Drupal\canvas\PropShape\PropShape>
   */
  private array $seen = [];

  /**
   * Whether looked-up prop shapes are recorded in $seen.
   *
   * Sub-property lookups made while composing a custom object shape are
   * implementation details, not component prop shapes, and must not pollute
   * the unique prop shape discovery.
   *
   * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeObjectPropsStorablePropShape()
   */
  private bool $recordSeen = TRUE;

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getUniquePropShapes(): array {
    return $this->seen;
  }

  /**
   * {@inheritdoc}
   */
  public function getStorablePropShape(PropShape $shape): StorablePropShape|ObjectPropsStorablePropShape|null {
    $candidate = $this->getCandidateStorablePropShape($shape);
    if ($candidate instanceof ObjectPropsStorablePropShape) {
      return $candidate;
    }
    return $candidate->toStorablePropShape();
  }

  /**
   * {@inheritdoc}
   */
  public function getStorablePropShapeForSubProperty(PropShape $shape): StorablePropShape|ObjectPropsStorablePropShape|null {
    $record_seen = $this->recordSeen;
    $this->recordSeen = FALSE;
    try {
      return $this->getStorablePropShape($shape);
    }
    finally {
      $this->recordSeen = $record_seen;
    }
  }

  public function getCandidateStorablePropShape(PropShape $shape): CandidateStorablePropShape|ObjectPropsStorablePropShape {
    if ($this->recordSeen) {
      $this->seen[$shape->uniquePropSchemaKey()] = $shape;
      ksort($this->seen);
    }
    // The default storable prop shape, if any. Prefer the original prop
    // shape, which may contain `$ref`, and allows
    // hook_canvas_storable_prop_shape_alter() implementations to suggest a
    // field type based on the definition name.
    // If that finds no field type storage, resolve `$ref`, which removes
    // `$ref` altogether. Try to find a field type storage again, but then the
    // decision relies solely on the final (fully resolved) JSON schema.
    $json_schema_type = JsonSchemaType::from($shape->schema['type']);
    // Custom object shapes ("groups") do not participate in the
    // candidate/alter flow themselves: each sub-property's storable shape
    // already went through it, when it was resolved through this repository.
    // The composite is only used when no field-type-based UX exists — a
    // hook_canvas_storable_prop_shape_alter() implementation providing a
    // compound field type (e.g. datetime_range for the date-range shape)
    // takes precedence.
    // @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeStorablePropShape()
    $composite_storable_prop_shape = NULL;
    $storable_prop_shape = $json_schema_type->computeStorablePropShape($shape, $this);
    if ($storable_prop_shape instanceof ObjectPropsStorablePropShape) {
      $composite_storable_prop_shape = $storable_prop_shape;
      $storable_prop_shape = NULL;
    }
    if ($storable_prop_shape === NULL) {
      $resolved_prop_shape = PropShape::normalize($shape->resolvedSchema);
      $resolved_storable_prop_shape = $json_schema_type->computeStorablePropShape($resolved_prop_shape, $this);
      if ($resolved_storable_prop_shape instanceof ObjectPropsStorablePropShape) {
        $composite_storable_prop_shape ??= $resolved_storable_prop_shape;
      }
      else {
        $storable_prop_shape = $resolved_storable_prop_shape;
      }
    }

    $alterable = $storable_prop_shape
      ? CandidateStorablePropShape::fromStorablePropShape($storable_prop_shape)
      // If no default storable prop shape exists, generate an empty
      // candidate.
      : new CandidateStorablePropShape($shape);

    // Allow modules to alter the default.
    $this->moduleHandler->alterDeprecated(
      'Hook hook_storage_prop_shape_alter is deprecated in canvas:1.0.0 and will be removed in canvas:2.0.0. Implement hook_canvas_storable_prop_shape_alter instead. See https://www.drupal.org/node/3561450',
      'storage_prop_shape',
      // The value that other modules can alter.
      $alterable,
    );
    $this->moduleHandler->alter(
      'canvas_storable_prop_shape',
      // The value that other modules can alter.
      $alterable,
    );

    // @todo DX: validate that the field type exists.
    // @todo DX: validate that the field prop exists.
    // @todo DX: validate that the field widget exists.

    // Fall back to the composite for custom object shapes when neither the
    // default flow nor an alter implementation provided a field-type-based UX.
    if ($composite_storable_prop_shape !== NULL && $alterable->toStorablePropShape() === NULL) {
      return $composite_storable_prop_shape;
    }

    return $alterable;
  }

}
