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
  public function getStorablePropShape(PropShape $shape): ?StorablePropShape {
    return $this->getCandidateStorablePropShape($shape)->toStorablePropShape();
  }

  public function getCandidateStorablePropShape(PropShape $shape): CandidateStorablePropShape {
    $this->seen[$shape->uniquePropSchemaKey()] = $shape;
    ksort($this->seen);
    // The default storable prop shape, if any. Prefer the original prop
    // shape, which may contain `$ref`, and allows
    // hook_canvas_storable_prop_shape_alter() implementations to suggest a
    // field type based on the definition name.
    // If that finds no field type storage, resolve `$ref`, which removes
    // `$ref` altogether. Try to find a field type storage again, but then the
    // decision relies solely on the final (fully resolved) JSON schema.
    $json_schema_type = JsonSchemaType::from($shape->schema['type']);
    $storable_prop_shape = JsonSchemaType::from($shape->schema['type'])->computeStorablePropShape($shape, $this);
    if ($storable_prop_shape === NULL) {
      $resolved_prop_shape = PropShape::normalize($shape->resolvedSchema);
      $storable_prop_shape = $json_schema_type->computeStorablePropShape($resolved_prop_shape, $this);
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

    return $alterable;
  }

}
