/**
 * Whether `filePath` is a top-level Canvas content template spec
 * (e.g. `content-templates/article.json`). Normalizes Windows-style separators.
 */
export function isTopLevelContentTemplateSpecPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return /(^|\/)content-templates\/[^/]+\.json$/.test(normalizedPath);
}

/**
 * Returns the content template slug from a top-level content template spec
 * path, or null if not a match.
 */
export function contentTemplateSlugFromTopLevelSpecPath(
  filePath: string,
): string | null {
  if (!isTopLevelContentTemplateSpecPath(filePath)) {
    return null;
  }

  const base = filePath.replaceAll('\\', '/').split('/').pop() ?? '';
  if (!base.endsWith('.json')) {
    return null;
  }

  return base.slice(0, -'.json'.length);
}
