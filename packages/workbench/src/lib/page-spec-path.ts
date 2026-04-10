/**
 * Whether `filePath` is a top-level Canvas page spec (e.g. `pages/home.json`).
 * Normalizes Windows-style separators.
 */
export function isTopLevelPageSpecPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return /(^|\/)pages\/[^/]+\.json$/.test(normalizedPath);
}

/**
 * Returns the page slug from a top-level page spec path, or null if not a match.
 */
export function pageSlugFromTopLevelSpecPath(filePath: string): string | null {
  if (!isTopLevelPageSpecPath(filePath)) {
    return null;
  }

  const base = filePath.replaceAll('\\', '/').split('/').pop() ?? '';
  if (!base.endsWith('.json')) {
    return null;
  }

  return base.slice(0, -'.json'.length);
}
