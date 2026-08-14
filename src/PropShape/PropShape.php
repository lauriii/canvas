<?php

declare(strict_types=1);

namespace Drupal\canvas\PropShape;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\ComponentPluginManager;
use Drupal\Component\Assertion\Inspector;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * A prop shape: a normalized component prop's JSON schema.
 *
 * Pass a `Component` plugin instance to `PropShape::getComponentProps()` and
 * receive an array of PropShape objects.
 *
 * @phpstan-import-type JsonSchema from \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
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
    if (JsonSchemaType::fromSdcPropJsonSchema($raw_sdc_prop_schema) === JsonSchemaType::Array && \array_key_exists('items', $raw_sdc_prop_schema)) {
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
    if (!\array_key_exists('type', $prop_schema) && \array_key_exists('$ref', $prop_schema)) {
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

    // TRICKY: In Drupal 11.4 and earlier, SDC appends `'object'` to every
    // prop's declared `type` (and then dedupes) so Twig can defer rendering
    // to the render pipeline. The originally declared type is therefore
    // always the first element.
    // @todo Simplify this logic when Canvas depends on Drupal 11.5 and later.
    // @see https://www.drupal.org/node/3589343
    // @see \Drupal\sdc\Component\ComponentMetadata::parseSchemaInfo()
    //
    // Prop definitions might not have been validated yet. Their `type` may
    // therefore be a string that does not correspond to any `JsonSchemaType`
    // enum case.
    // If `JsonSchemaType::from()` was called, it would throw `\ValueError` on
    // such input; this normalization must tolerate it and simply pass it
    // through (any downstream shape comparison will then not match a known
    // shape, which is the desired outcome).
    // @see \Drupal\Tests\canvas\Unit\PropShape\PropShapeNormalizeTest
    $normalized_prop_schema['type'] = \strtolower(((array) $prop_schema['type'])[0]);

    // If this is a `type: object` with not a `$ref` but `properties`, normalize
    // those too.
    if ($normalized_prop_schema['type'] === JsonSchemaType::Object->value && \array_key_exists('properties', $normalized_prop_schema)) {
      $normalized_prop_schema['properties'] = \array_map(
        fn (array $prop_schema) => self::normalizePropSchema($prop_schema),
        $normalized_prop_schema['properties'],
      );
    }

    // Omit the resolved $ref URI that schema resolution injects. Canvas uses
    // the JSON-Schema Draft-07 dialect, whose id keyword is `$id` (not
    // Draft-04's `id`). justinrainbow/json-schema only emits `$id` as of
    // 6.9.0; earlier versions injected `id` regardless of dialect. Strip both
    // so the URI never leaks into the prop shape on either version
    // (drupal/core-recommended still pins ~6.8.2 until core adopts 6.9.0).
    // @see https://github.com/jsonrainbow/json-schema/issues/911
    // @see \JsonSchema\SchemaStorage::resolveRefSchema()
    // @see \JsonSchema\Uri\UriRetriever::retrieve()
    unset($normalized_prop_schema['id'], $normalized_prop_schema['$id']);

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
      \assert(\is_string($extension_name));
      $schema_json_path = $extension->getPath() . '/schema.json';
      if (!file_exists($schema_json_path)) {
        continue;
      }
      // @phpstan-ignore argument.type
      $json = json_decode(file_get_contents($schema_json_path), TRUE);
      if (!\is_array($json) || !\array_key_exists('$defs', $json)) {
        continue;
      }
      \assert(Inspector::assertAllStrings(\array_keys($json['$defs'])));
      $extension_type = \array_key_exists($extension_name, $installed_modules) ? 'module' : 'theme';
      $known_normalized += array_combine(
        \array_map(
          fn(string $def_name) => "json-schema-definitions://$extension_name.$extension_type/$def_name",
          \array_keys($json['$defs']),
        ),
        \array_map(
          fn(array $prop_schema) => self::normalizePropSchema(self::resolveSchemaReferences($prop_schema)),
          $json['$defs'],
        ),
      );
    }
    // No 2 modules should provide the same definition; otherwise we won't know
    // whose was intended.
    $unique_keys_as_values = \array_map(
      fn (array $schema) => self::normalize($schema)->uniquePropSchemaKey(),
      $known_normalized
    );
    if (count($known_normalized) > count(array_unique($unique_keys_as_values))) {
      throw new \LogicException(\sprintf(
        '🐛 Duplicate $ref definitions detected: %s.',
        implode(',', \array_keys(array_diff_key($known_normalized, array_unique($unique_keys_as_values))))
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

  /**
   * Whether the given JSON schema for a prop is considered plain or rich prose.
   *
   * - Plain prose: `type: string` — single- or multi-line
   * - Rich prose: `type: string, contentMediaType: text/html` — the
   *   `x-formatting-context` does not matter. Invalid `x-formatting-contexts`
   *   are blocked during discovery of components from ever making it into
   *   Canvas component trees.
   *
   * TRICKY: this is a Canvas concept, not a JSON Schema concept. Hence this
   * method does not live in:
   * - \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat
   * - \Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType
   *
   * @param JsonSchema $prop_schema
   *   The JSON schema for a component prop.
   *
   * @return bool
   *
   * @see \Drupal\canvas\Plugin\Validation\Constraint\StringSemanticsConstraint::PROSE
   * @see \Drupal\canvas\Plugin\Validation\Constraint\StringSemanticsConstraint::MARKUP
   */
  public static function isPlainOrRichProse(array $prop_schema): bool {
    // TRICKY: prose should not have a minimum or maximum length, because each
    // language can have a short or long word for the same concept, which would
    // make it impossible to translate from one language to another. Plus,
    // translation systems tend to not deal with minimum and maximum length.
    // @todo Consider allowing `minLength` and/or `maxLength` JSON schema constraints in the future, but then translation systems must also respect this; otherwise they would trigger validation errors.
    // @see https://git.drupalcode.org/project/canvas/-/work_items/3591690
    // For example: "Hello" (5 characters) in English is "Bonjour" (7
    // characters) in French.
    return match(static::normalizePropSchema($prop_schema)) {
      // Plain prose, single line.
      ['type' => 'string'] => TRUE,
      // Plain prose, multi-line.
      ['type' => 'string', 'pattern' => '(.|\r?\n)*'] => TRUE,
      // Rich prose, block.
      ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'block'] => TRUE,
      // Rich prose, inline.
      ['type' => 'string', 'contentMediaType' => 'text/html', 'x-formatting-context' => 'inline'] => TRUE,
      default => FALSE,
    };
  }

  /**
   * Computes the translation-relevant string shape of a prop, if any.
   *
   * Single source of truth for which prop *shapes* hold author-entered,
   * translatable text. Translatable shapes are plain strings (single- and
   * multi-line), rich (HTML) strings and URI-esque strings. So:
   * - type: string (single-line)
   * - type: string, pattern: (.|\r?\n)* (multi-line)
   * - type: string, format: iri
   * - type: string, format: iri-reference
   * - type: string, format: uri
   * - type: string, format: uri-reference
   * - type: string, contentMediaType: text/html, x-formatting-context: inline
   * - type: string, contentMediaType: text/html, x-formatting-context: block
   *
   * Cardinality is irrelevant — an array of translatable items is translatable
   * — so this peeks inside `type: array` at its item shape.
   *
   * The returned shape is reduced to the keys that matter to translation
   * systems: `pattern` (multi-line), `contentMediaType` plus
   * `x-formatting-context` (rich text) and `format` (URI-esque).
   * `type: string` is implied and omitted. `x-formatting-context` does not
   * affect *whether* the shape is translatable, but it is retained because
   * translation systems must respect it: an `inline` rich text prop must not
   * receive block-level HTML (let alone multiple paragraphs) from a
   * translation. This reduced shape is what a component version retains in
   * its `prop_field_definitions`, so that translatability can be derived —
   * and re-derived, if the rules ever change — from the shape itself rather
   * than from a stored boolean.
   *
   * NOTE: whether a prop is *actually* translatable also depends on how it is
   * populated: a reference-backed prop holds structured data, not
   * author-entered text.
   *
   * @param JsonSchema $prop_schema
   *   A prop's JSON schema: raw SDC metadata or a (normalized) prop shape.
   *
   * @return JsonSchema|null
   *   The reduced translation-relevant string shape (possibly empty, for a
   *   plain single-line string), or NULL if the shape does not hold
   *   translatable text. (See `type: canvas.json_schema_props`.)
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsonSchemaPropsComponentSourceBase::isExplicitInputTranslatable()
   * @todo Consider adding alter hook to allow more shapes to be translatable in https://drupal.org/i/3584178
   */
  public static function getTranslatableStringShape(array $prop_schema): ?array {
    // For translatability, cardinality is irrelevant, only the shape matters,
    // so peek inside any array prop at its item shape. Only an array carries an
    // `items` key, so checking the key (rather than `type`) works whether the
    // shape has been normalized or is still raw SDC metadata.
    if (\array_key_exists('items', $prop_schema) && \is_array($prop_schema['items'])) {
      $prop_schema = $prop_schema['items'];
    }
    $normalized = static::normalizePropSchema($prop_schema);
    if (self::isPlainOrRichProse($normalized)) {
      // The exact-match nature of ::isPlainOrRichProse() guarantees `pattern`,
      // `contentMediaType` and `x-formatting-context` hold the sole values
      // prose allows.
      return \array_intersect_key($normalized, \array_flip(['pattern', 'contentMediaType', 'x-formatting-context']));
    }
    if (
      ($normalized['type'] ?? NULL) === 'string'
      && \array_key_exists('format', $normalized)
      && JsonSchemaStringFormat::tryFrom($normalized['format'])?->isUriEsque()
    ) {
      // Deliberately reduced to just `format`: any additional constraint (e.g.
      // a `pattern`) does not affect translatability.
      return ['format' => $normalized['format']];
    }
    return NULL;
  }

}
