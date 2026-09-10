<?php

declare(strict_types=1);

namespace Drupal\canvas\Icon;

/**
 * Defines the JSON schema shape of `icon` component props.
 *
 * An icon prop stores the core Icon API's full icon identifier
 * (`pack_id:icon_id`) as a plain string. Restricting an icon prop to a subset
 * of the installed icon packs ("scoping") is expressed as a generated JSON
 * Schema `pattern` anchored to the allowed pack ids, so the existing JSON
 * Schema validation enforces the scope server-side without a custom keyword.
 *
 * TRICKY: prop shape normalization dereferences `$ref`s and lets sibling
 * keywords win. An unscoped icon prop therefore normalizes to the well-known
 * `{type, $ref}` shape, while a scoped one normalizes to `{type, pattern}`
 * with the generated scope pattern replacing the base pattern. Both forms must
 * be recognized as the icon shape.
 *
 * @see \Drupal\canvas\Plugin\ComponentPluginManager::resolveJsonSchemaReferences()
 * @see \Drupal\canvas\PropShape\PropShape::normalizePropSchema()
 * @see \Drupal\Core\Theme\Icon\IconDefinition::createIconId()
 *
 * @internal
 */
final class IconPropShape {

  public const string SCHEMA_REF = 'json-schema-definitions://canvas.module/icon';

  /**
   * The base pattern in the `icon` definition in schema.json.
   *
   * Icon pack plugin ids may contain only lowercase letters, numbers, and
   * underscores.
   *
   * @see \Drupal\Core\Theme\Icon\Plugin\IconPackManager::processDefinition()
   */
  public const string BASE_PATTERN = '^[a-z0-9_]+:.+$';

  /**
   * Matches generated scope patterns, e.g. `^(phosphor|heroicons):.+$`.
   */
  private const string SCOPE_PATTERN_REGEX = '/^\^\(([a-z0-9_]+(\|[a-z0-9_]+)*)\):\.\+\$$/';

  /**
   * Checks whether a (resolved, normalized) prop schema is the icon shape.
   *
   * @param array<string, mixed> $schema
   *   A JSON schema for a single prop.
   */
  public static function isIconSchema(array $schema): bool {
    // TRICKY: SDC appends `object` to every prop's declared `type`; the
    // originally declared type is always the first element.
    // @see \Drupal\Core\Theme\Component\ComponentMetadata::parseSchemaInfo()
    $type = ((array) ($schema['type'] ?? NULL))[0] ?? NULL;
    if ($type !== 'string') {
      return FALSE;
    }
    if (($schema['$ref'] ?? NULL) === self::SCHEMA_REF) {
      return TRUE;
    }
    $pattern = $schema['pattern'] ?? NULL;
    if (!\is_string($pattern)) {
      return FALSE;
    }
    return $pattern === self::BASE_PATTERN || \preg_match(self::SCOPE_PATTERN_REGEX, $pattern) === 1;
  }

  /**
   * Dereferences the icon `$ref` in a prop schema, siblings winning.
   *
   * Code components' ephemeral SDC plugin definitions keep raw `$ref`s, and
   * JSON Schema validators ignore keywords that are siblings of `$ref` — which
   * would leave an icon prop's pack-scope `pattern` unenforced. Because the
   * icon definition in schema.json is trivially small, it is inlined here
   * rather than resolved through the schema storage.
   *
   * @param array<string, mixed> $schema
   *   A JSON schema for a single prop.
   *
   * @return array<string, mixed>
   *   The schema with the icon `$ref` replaced by its constraints. Schemas
   *   not using the icon `$ref` are returned unchanged.
   *
   * @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponentDiscovery::buildEphemeralSdcPluginInstance()
   */
  public static function dereference(array $schema): array {
    if (($schema['$ref'] ?? NULL) !== self::SCHEMA_REF) {
      return $schema;
    }
    unset($schema['$ref']);
    $schema['type'] ??= 'string';
    $schema['pattern'] ??= self::BASE_PATTERN;
    return $schema;
  }

  /**
   * Builds the scope pattern restricting values to the given icon packs.
   *
   * @param list<string> $pack_ids
   *   Icon pack plugin ids.
   */
  public static function buildScopePattern(array $pack_ids): string {
    \assert($pack_ids !== []);
    // Pack ids are validated plugin ids (`[a-z0-9_]+`), which contain no regex
    // metacharacters; quote defensively anyway.
    return '^(' . \implode('|', \array_map(\preg_quote(...), $pack_ids)) . '):.+$';
  }

  /**
   * Extracts the allowed pack ids from an icon prop schema.
   *
   * @param array<string, mixed> $schema
   *   A JSON schema for a single prop.
   *
   * @return list<string>|null
   *   The allowed pack ids, or NULL when all installed packs are allowed.
   */
  public static function getAllowedPackIds(array $schema): ?array {
    $pattern = $schema['pattern'] ?? NULL;
    if (!\is_string($pattern) || \preg_match(self::SCOPE_PATTERN_REGEX, $pattern, $matches) !== 1) {
      return NULL;
    }
    return \explode('|', $matches[1]);
  }

}
