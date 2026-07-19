/**
 * Helpers for the icon prop: its pack-scope JSON Schema `pattern`, schema
 * detection, and resolving a stored id into a renderable value.
 *
 * An icon prop stores the core Icon API's full icon id (`pack_id:icon_id`).
 * Scoping the prop to a subset of the installed icon packs is expressed as a
 * generated `pattern` anchored to the allowed pack ids, enforced server-side
 * by the existing JSON Schema validation.
 *
 * Mirrors \Drupal\canvas\Icon\IconPropShape and \Drupal\canvas\Icon\IconResolver
 * (PHP).
 */

import type { CodeComponentPropIconPreviewValue } from '@/types/CodeComponent';
import type { IconPack } from '@/types/Icons';

const SCOPE_PATTERN_REGEX = /^\^\(([a-z0-9_]+(\|[a-z0-9_]+)*)\):\.\+\$$/;

/**
 * The base pattern of the `icon` definition in schema.json.
 */
export const ICON_BASE_PATTERN = '^[a-z0-9_]+:.+$';

/**
 * The `$ref` marking a code component prop as an icon.
 */
export const ICON_SCHEMA_REF = 'json-schema-definitions://canvas.module/icon';

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

/**
 * Checks whether a code component prop's JSON Schema is an icon prop.
 *
 * True for the icon `$ref` and for an icon-shape `pattern` (base or a
 * generated pack-scope pattern, e.g. `^(lucide|phosphor):.+$`), since
 * server-side normalization may dereference the `$ref` into a `pattern`.
 * Mirrors \Drupal\canvas\Icon\IconPropShape::isIconSchema().
 */
export function isIconSchema(
  schema?: { type?: unknown; $ref?: string; pattern?: string } | null,
): boolean {
  if (!schema || schema.type !== 'string') {
    return false;
  }
  return schema.$ref === ICON_SCHEMA_REF || isIconPattern(schema.pattern);
}

/**
 * Resolves a stored icon id into its renderable value from the installed packs.
 *
 * An icon's `pack_id:icon_id` is globally unique, so this searches across all
 * installed packs regardless of any pack scope on the prop (scoping only
 * limits what the picker offers). Returns null for an empty value or an id not
 * present in the given packs, mirroring the server-side resolution.
 *
 * @see \Drupal\canvas\Icon\IconResolver::resolve()
 */
export function resolveIconValue(
  value: unknown,
  packs: IconPack[] | undefined,
): CodeComponentPropIconPreviewValue | null {
  if (typeof value !== 'string' || value === '') {
    return null;
  }
  const icon = (packs ?? [])
    .flatMap((pack) => pack.icons)
    .find((packIcon) => packIcon.id === value);
  if (!icon) {
    return null;
  }
  return {
    id: icon.id,
    ...(icon.svg ? { svg: icon.svg } : {}),
    ...(icon.url ? { url: icon.url } : {}),
  };
}
