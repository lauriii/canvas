export function isComponentMetadataPath(filePath: string): boolean {
  const normalizedPath = filePath.replaceAll('\\', '/');
  return (
    /(^|\/)component\.yml$/.test(normalizedPath) ||
    /(^|\/)[^/]+\.component\.yml$/.test(normalizedPath)
  );
}
