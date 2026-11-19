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
  case VideoEmbed = 'json-schema-definitions://canvas.module/video-embed';
  case Document = 'json-schema-definitions://canvas.module/document';
  case ContentEntityReference = 'json-schema-definitions://canvas.module/content-entity-reference';

  /**
   * Whether the given JSON schema for a prop is a content-entity-reference.
   *
   * This matches both cardinalities: a single reference and a list of
   * references.
   *
   * @param JsonSchema $prop_schema
   *   The JSON schema for a component prop.
   *
   * @return bool
   *
   * @see ::isContentEntityReferenceObject()
   * @see ::isContentEntityReferenceObjectList()
   */
  public static function isContentEntityReference(array $prop_schema): bool {
    return self::isContentEntityReferenceObject($prop_schema)
      || self::isContentEntityReferenceObjectList($prop_schema);
  }

  /**
   * Whether the given JSON schema for a prop is a single reference.
   *
   * This only matches `type: object`. A list of references does not match.
   *
   * @param JsonSchema $prop_schema
   *   The JSON schema for a component prop.
   *
   * @return bool
   *
   * @see ::isContentEntityReference()
   * @see ::isContentEntityReferenceObjectList()
   */
  public static function isContentEntityReferenceObject(array $prop_schema): bool {
    $normalized = PropShape::normalizePropSchema($prop_schema);
    return ($normalized['type'] === 'object'
      && \array_key_exists('$ref', $normalized)
      && $normalized['$ref'] === self::ContentEntityReference->value);
  }

  /**
   * Whether the given JSON schema for a prop is a list of references.
   *
   * A list of references is a `type: array` prop. Its `items` schema is a
   * single reference.
   *
   * @param JsonSchema $prop_schema
   *   The JSON schema for a component prop.
   *
   * @return bool
   *
   * @see ::isContentEntityReference()
   * @see ::isContentEntityReferenceObject()
   */
  public static function isContentEntityReferenceObjectList(array $prop_schema): bool {
    $normalized = PropShape::normalizePropSchema($prop_schema);
    if ($normalized['type'] !== 'array') {
      return FALSE;
    }
    // A list of references has one schema for all its items. It must also
    // declare `type`, because the check below requires it.
    $items = $normalized['items'] ?? NULL;
    return \is_array($items)
      && \array_key_exists('type', $items)
      && self::isContentEntityReferenceObject($items);
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

  /**
   * Returns the file extensions the shape's `src` property allows.
   *
   * @return list<string>
   *   The `x-allowed-file-extensions` annotation on the shape's `src`
   *   property, or an empty list when the shape declares none.
   */
  public function allowedFileExtensions(): array {
    $extensions = $this->asPropShape()->resolvedSchema['properties']['src']['x-allowed-file-extensions'] ?? [];
    \assert(\is_array($extensions) && \array_is_list($extensions));
    /** @var list<string> $extensions */
    return $extensions;
  }

}
