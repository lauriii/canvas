/**
 * Whether a file path is a component mock spec (`mocks.json` or
 * `<name>.mocks.json`). Mock edits change what a mounted preview renders, not
 * the discovery structure, so they refresh the preview in place.
 */
export function isMockSpecPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return (
    /(^|\/)mocks\.json$/.test(normalizedPath) ||
    /(^|\/)[^/]+\.mocks\.json$/.test(normalizedPath)
  );
}
