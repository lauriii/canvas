export function toViteFsUrl(filePath: string): string {
  const normalizedPath = filePath.replaceAll('\\', '/');
  const prefixedPath = normalizedPath.startsWith('/')
    ? normalizedPath
    : `/${normalizedPath}`;
  return `/@fs${prefixedPath}`;
}

const SUPPORTED_PREVIEW_EXTENSIONS = new Set(['.js', '.jsx', '.ts', '.tsx']);

export function isSupportedPreviewModulePath(filePath: string): boolean {
  const lastPathSegment = filePath
    .replaceAll('\\', '/')
    .split('/')
    .pop()
    ?.toLowerCase();
  if (!lastPathSegment) {
    return false;
  }

  const dotIndex = lastPathSegment.lastIndexOf('.');
  const extension = dotIndex === -1 ? '' : lastPathSegment.slice(dotIndex);
  return SUPPORTED_PREVIEW_EXTENSIONS.has(extension);
}
