<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * A prop shape: a normalized component prop's JSON schema.
 *
 * Pass a `Component` plugin instance to `PropShape::getComponentProps()` and
 * receive an array of PropShape objects.
 *
 * @phpstan-type JsonSchema array<string, mixed>
 * @internal
 */
final class PropShape {

  /**
   * The resolved schema of the prop shape.
   */
  public readonly array $resolvedSchema;

  public function __construct(
    // The schema of the prop shape.
    public readonly array $schema,
  ) {
    $normalized = self::normalizePropSchema($this->schema);
    if ($schema !== $normalized) {
      throw new \InvalidArgumentException(\sprintf("The passed in schema (%s) should be normalized (%s).", print_r($schema, TRUE), print_r($normalized, TRUE)));
    }
    $this->resolvedSchema = self::resolveSchemaReferences($schema);
  }

  public static function normalize(array $raw_sdc_prop_schema): PropShape {
    return new PropShape(self::normalizePropSchema($raw_sdc_prop_schema));
  }

  /**
   * Standardizes a prop shape: minimizes JSON Schema, if possible to just $ref.
   *
   * (If just `$ref`, we call the result a "well-known prop shape" because it
   * has a defined name.)
   *
   * @param array $raw_sdc_prop_schema
   *
   * @return \Drupal\canvas\PropShape\PropShape
   */
  public static function standardize(array $raw_sdc_prop_schema): PropShape {
    // Resolving alone is not enough, also normalize: otherwise no match may be
    // found due to frivolities such as `title` being present.
    $resolved_normalized = self::normalizePropSchema(self::resolveSchemaReferences($raw_sdc_prop_schema));
    // TRICKY: specifically do NOT use strict comparisons here, because the
    // props of an object-shaped prop may be ordered differently.
    // @see tests/modules/canvas_test_sdc/components/image-without-ref/image-without-ref.component.yml
    // @phpstan-ignore function.strict
    $well_known_prop_shape = array_search($resolved_normalized, self::getWellKnownPropShapes(), FALSE);

    if ($well_known_prop_shape !== FALSE) {
      return PropShape::normalize([
        'type' => $resolved_normalized['type'],
        '$ref' => $well_known_prop_shape,
      ]);
    }

    // If this is an array shape, try standardizing to an array of a well-known
    // shape. But make sure to keep `minItems`, `maxItems` etc!
    if (JsonSchemaType::fromSdcPropJsonSchema($raw_sdc_prop_schema) === JsonSchemaType::Array && array_key_exists('items', $raw_sdc_prop_schema)) {
      $resolved_normalized_array_items = self::normalizePropSchema(self::resolveSchemaReferences($raw_sdc_prop_schema['items']));
      // TRICKY: specifically do NOT use strict comparisons here, because the
      // props of an object-shaped prop may be ordered differently.
      // @see tests/modules/canvas_test_sdc/components/image-without-ref/image-without-ref.component.yml
      // @phpstan-ignore function.strict
      $well_known_prop_shape = array_search($resolved_normalized_array_items, self::getWellKnownPropShapes(), FALSE);
      if ($well_known_prop_shape !== FALSE) {
        $other_key_value_pairs = array_diff_key($resolved_normalized, array_flip(['type', 'items']));
        return PropShape::normalize([
          'type' => 'array',
          'items' => [
            '$ref' => $well_known_prop_shape,
            'type' => $resolved_normalized_array_items['type'],
          ],
          ...$other_key_value_pairs,
        ]);
      }
    }

    return PropShape::normalize($raw_sdc_prop_schema);
  }

  public function getType(): JsonSchemaType {
    return JsonSchemaType::from($this->resolvedSchema['type']);
  }

  /**
   * @param JsonSchema $schema
   * @return JsonSchema
   */
  private static function resolveSchemaReferences(array $schema): array {
    return self::componentPluginManager()->resolveJsonSchemaReferences($schema);
  }

  /**
   * @todo Rename to key()
   */
  public function uniquePropSchemaKey(): string {
    // A reliable key thanks to ::normalizePropSchema().
    return urldecode(http_build_query($this->schema));
  }

  /**
   * @param JsonSchema $prop_schema
   *
   * @return JsonSchema
   */
  public static function normalizePropSchema(array $prop_schema): array {
    ksort($prop_schema);

    // Normalization is not (yet) possible when `$ref`s are still present.
    // @todo Once https://www.drupal.org/i/3352063 is fixed and Canvas requires it, convert this to a \LogicException instead, because it should not be possible to occur anymore.
    if (!array_key_exists('type', $prop_schema) && array_key_exists('$ref', $prop_schema)) {
      return $prop_schema;
    }

    // Ensure that `type` is always listed first.
    $normalized_prop_schema = ['type' => $prop_schema['type']] + $prop_schema;

    // Title, description, examples and meta:enum (and its associated optional
    // x-translation-context) do not affect which field type + widget should be
    // used.
    unset($normalized_prop_schema['title']);
    unset($normalized_prop_schema['description']);
    unset($normalized_prop_schema['examples']);
    unset($normalized_prop_schema['meta:enum']);
    unset($normalized_prop_schema['x-translation-context']);
    // @todo Add support to `SDC` for `default` in https://www.drupal.org/project/canvas/issues/3462705?
    // @see https://json-schema.org/draft/2020-12/draft-bhutton-json-schema-validation-00#rfc.section.9.2
    unset($normalized_prop_schema['default']);

    $normalized_prop_schema['type'] = JsonSchemaType::from(
    // TRICKY: SDC always allowed `object` for Twig integration reasons.
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
      is_array($prop_schema['type']) ? $prop_schema['type'][0] : $prop_schema['type']
    )->value;

    // If this is a `type: object` with not a `$ref` but `properties`, normalize
    // those too.
    if ($normalized_prop_schema['type'] === JsonSchemaType::Object->value && array_key_exists('properties', $normalized_prop_schema)) {
      $normalized_prop_schema['properties'] = array_map(
        fn (array $prop_schema) => self::normalizePropSchema($prop_schema),
        $normalized_prop_schema['properties'],
      );
    }

    // Omit the ID containing the resolved $ref URI.
    // @see \JsonSchema\SchemaStorage::resolveRefSchema()
    // @see \JsonSchema\Uri\UriRetriever::retrieve()
    unset($normalized_prop_schema['id']);

    return $normalized_prop_schema;
  }

  /**
   * @return array<string, JsonSchema>
   *
   * @see https://en.wikipedia.org/wiki/Well-known_URI
   * @todo Before making this a public API that allows contrib to define more well-known prop shapes, actually spec it out beyond "/schema.json in extension root and define $defs". It is too undefined to be a reliable public API right now
   */
  private static function getWellKnownPropShapes(): array {
    static $known_normalized;
    if (isset($known_normalized)) {
      return $known_normalized;
    }

    // Support `$ref`s defined by Drupal extensions in a `/schema.json` file.
    // So: `json-schema-definitions://<extension>.<extension type>/<definition>`
    // @see \Drupal\canvas\JsonSchemaDefinitionsStreamwrapper
    // @todo Validate the `/schema.json` files.
    $known_normalized = [];
    $installed_modules = self::moduleHandler()->getModuleList();
    $installed_themes = self::themeHandler()->listInfo();
    \assert(empty(array_intersect_key($installed_modules, $installed_themes)));
    $installed_extensions = $installed_modules + $installed_themes;
    foreach ($installed_extensions as $extension_name => $extension) {
      \assert(is_string($extension_name));
      $schema_json_path = $extension->getPath() . '/schema.json';
      if (!file_exists($schema_json_path)) {
        continue;
      }
      // @phpstan-ignore argument.type
      $json = json_decode(file_get_contents($schema_json_path), TRUE);
      if (!is_array($json) || !array_key_exists('$defs', $json)) {
        continue;
      }
      \assert(Inspector::assertAllStrings(array_keys($json['$defs'])));
      $extension_type = array_key_exists($extension_name, $installed_modules) ? 'module' : 'theme';
      $known_normalized += array_combine(
        array_map(
          fn(string $def_name) => "json-schema-definitions://$extension_name.$extension_type/$def_name",
          array_keys($json['$defs']),
        ),
        array_map(
          fn(array $prop_schema) => self::normalizePropSchema(self::resolveSchemaReferences($prop_schema)),
          $json['$defs'],
        ),
      );
    }
    // No 2 modules should provide the same definition; otherwise we won't know
    // whose was intended.
    $unique_keys_as_values = array_map(
      fn (array $schema) => self::normalize($schema)->uniquePropSchemaKey(),
      $known_normalized
    );
    if (count($known_normalized) > count(array_unique($unique_keys_as_values))) {
      throw new \LogicException(\sprintf(
        '🐛 Duplicate $ref definitions detected: %s.',
        implode(',', array_keys(array_diff_key($known_normalized, array_unique($unique_keys_as_values))))
      ));
    }
    return $known_normalized;
  }

  private static function moduleHandler(): ModuleHandlerInterface {
    return \Drupal::moduleHandler();
  }

  private static function themeHandler(): ThemeHandlerInterface {
    return \Drupal::service(ThemeHandlerInterface::class);
  }

  private static function componentPluginManager(): ComponentPluginManager {
    return \Drupal::service(ComponentPluginManager::class);
  }

}
