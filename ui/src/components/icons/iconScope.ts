/**
 * Helpers for the icon prop's pack-scope JSON Schema `pattern`.
 *
 * An icon prop stores the core Icon API's full icon id (`pack_id:icon_id`).
 * Scoping the prop to a subset of the installed icon packs is expressed as a
 * generated `pattern` anchored to the allowed pack ids, enforced server-side
 * by the existing JSON Schema validation.
 *
 * Mirrors \Drupal\canvas\Icon\IconPropShape (PHP).
 */

const SCOPE_PATTERN_REGEX = /^\^\(([a-z0-9_]+(\|[a-z0-9_]+)*)\):\.\+\$$/;

/**
 * The base pattern of the `icon` definition in schema.json.
 */
export const ICON_BASE_PATTERN = '^[a-z0-9_]+:.+$';

/**
 * Checks whether a JSON Schema `pattern` is an icon shape pattern.
 *
 * True for the base pattern and for generated pack-scope patterns. Mirrors
 * \Drupal\canvas\Icon\IconPropShape::isIconSchema() so a schema that lost its
 * `$ref` (e.g. after server-side normalization) still derives as an icon.
 */
export function isIconPattern(pattern?: string | null): boolean {
  return (
    pattern === ICON_BASE_PATTERN ||
    (typeof pattern === 'string' && SCOPE_PATTERN_REGEX.test(pattern))
  );
}

/**
 * Builds the scope pattern restricting values to the given icon packs.
 */
export function buildIconScopePattern(packIds: string[]): string {
  return `^(${packIds.join('|')}):.+$`;
}

/**
 * Extracts the allowed pack ids from a scope pattern.
 *
 * @returns The allowed pack ids, or null when all installed packs are allowed.
 */
export function parseIconScopePattern(
  pattern?: string | null,
): string[] | null {
  if (!pattern) {
    return null;
  }
  const match = pattern.match(SCOPE_PATTERN_REGEX);
  return match ? match[1].split('|') : null;
}
