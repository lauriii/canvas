<?php

declare(strict_types=1);

namespace Drupal\canvas\JsonSchemaInterpreter;

use Drupal\canvas\PropShape\PropShape;

/**
 * Canonical `$ref` URIs for Canvas-provided `type: object` JSON Schemas.
 *
 * @see schema.json
 * @see \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType::computeStorablePropShape()
 *
 * @internal
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\PropShape\PropShape
 */
enum JsonSchemaObjectRef: string {

  case Image = 'json-schema-definitions://canvas.module/image';
  case Video = 'json-schema-definitions://canvas.module/video';
  case ContentEntityReference = 'json-schema-definitions://canvas.module/content-entity-reference';

  /**
   * Whether the given JSON schema for a prop is a content-entity-reference.
   *
   * @param JsonSchema $prop_schema
   *   The JSON schema for a component prop.
   *
   * @return bool
   */
  public static function isContentEntityReference(array $prop_schema): bool {
    $normalized = PropShape::normalizePropSchema($prop_schema);
    return ($normalized['type'] === 'object'
      && \array_key_exists('$ref', $normalized)
      && $normalized['$ref'] === self::ContentEntityReference->value);
  }

  /**
   * Returns the full `{type: object, $ref: <URI>}` prop shape array.
   *
   * @return array{type: string, '$ref': string}
   *   Shape array suitable for constructing a
   *   \Drupal\canvas\PropShape\PropShape.
   */
  public function asPropShapeArray(): array {
    return [
      'type' => 'object',
      '$ref' => $this->value,
    ];
  }

  /**
   * Returns the full `{type: object, $ref: <URI>}` prop shape.
   *
   * @return \Drupal\canvas\PropShape\PropShape
   */
  public function asPropShape(): PropShape {
    return new PropShape($this->asPropShapeArray());
  }

}
