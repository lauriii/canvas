import { randomUUID } from 'crypto';

// Strict RFC 4122 UUID match: version digit 1–5, variant digit 8/9/a/b. The
// loose 8-4-4-4-12 hex shape isn't sufficient because Drupal's UUID
// validator rejects values like "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa"
// (no proper version/variant). AI-generated specs sometimes ship those, so
// callers replace failures with a freshly generated v4.
const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/**
 * Returns true if `value` is an RFC 4122 v1–5 UUID. Mirrors the validation
 * Drupal's `Uuid` constraint applies server-side, so callers can decide
 * whether a string can be used as-is or needs to be replaced with a fresh
 * UUID before being pushed.
 */
export function isValidUuid(value: string): boolean {
  return UUID_RE.test(value);
}

/**
 * Builds a stable map from authored element keys to valid UUIDs. Keys that
 * already pass `isValidUuid` are kept; everything else is replaced with a
 * fresh v4 UUID. Used by both the pages and content-templates push paths so
 * authored specs (e.g. ones produced by an AI agent) with placeholder
 * keys still push successfully.
 */
export function buildElementKeyToUuidMap(
  elementKeys: Iterable<string>,
): Map<string, string> {
  const result = new Map<string, string>();
  for (const key of elementKeys) {
    result.set(key, isValidUuid(key) ? key : randomUUID());
  }
  return result;
}

/**
 * Reverse-walks `elements`' slot definitions and returns a map from each
 * child element key to `{ parentKey, slot }`. Elements that aren't named in
 * any parent's slots are absent from the map (i.e. root-level).
 */
export function buildChildToParentMap(
  elements: Record<string, { slots?: Record<string, string[]> }>,
): Map<string, { parentKey: string; slot: string }> {
  const result = new Map<string, { parentKey: string; slot: string }>();
  for (const [key, element] of Object.entries(elements)) {
    if (!element.slots) continue;
    for (const [slotName, childKeys] of Object.entries(element.slots)) {
      for (const childKey of childKeys) {
        result.set(childKey, { parentKey: key, slot: slotName });
      }
    }
  }
  return result;
}
