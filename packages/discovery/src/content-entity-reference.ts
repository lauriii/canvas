export interface ContentEntityReferenceTarget {
  entityTypeId: string;
  bundle: string | null;
}

/**
 * Derives the host entity target encoded by an entity field expression.
 *
 * Drupal remains authoritative for parsing and validating the complete
 * expression. Local consumers only need the leading host data definition to
 * offer previews before a component is pushed.
 */
export function getContentEntityReferenceTarget(
  expressions: string[] | undefined,
): ContentEntityReferenceTarget | null {
  const expression = expressions?.[0];
  if (typeof expression !== 'string') {
    return null;
  }

  const match = expression.match(/^[^␜]*␜entity:([^:␝]+)(?::([^␝]+))?␝/u);
  if (!match?.[1]) {
    return null;
  }

  return {
    entityTypeId: match[1],
    bundle: match[2] ?? null,
  };
}
