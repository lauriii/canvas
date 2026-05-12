const ASSET_EXTENSIONS = [
  '.jpg',
  '.jpeg',
  '.png',
  '.gif',
  '.webp',
  '.avif',
  '.ico',
  '.svg',
  '.mp3',
  '.wav',
  '.ogg',
  '.flac',
  '.aac',
  '.m4a',
  '.mp4',
  '.webm',
  '.mov',
  '.avi',
  '.woff',
  '.woff2',
  '.ttf',
  '.otf',
  '.eot',
] as const;

export interface RewriteAssetImportsOptions {
  componentId?: string;
  manifestAssetNames?: string[];
}

function stripQueryAndHash(value: string): string {
  return value.split('?')[0].split('#')[0];
}

function isAssetSpecifier(value: string): boolean {
  if (value.includes('?react')) {
    return false;
  }
  const source = stripQueryAndHash(value).toLowerCase();
  return ASSET_EXTENSIONS.some((extension) => source.endsWith(extension));
}

function normalizeRelativeAssetPath(source: string): string {
  const parts: string[] = [];
  for (const part of stripQueryAndHash(source).split('/')) {
    if (!part || part === '.') {
      continue;
    }
    if (part === '..') {
      parts.pop();
      continue;
    }
    parts.push(part);
  }
  return parts.join('/');
}

function hasParentDirectoryTraversal(source: string): boolean {
  return stripQueryAndHash(source).split('/').includes('..');
}

function toCanvasAssetSpecifier(
  source: string,
  options: RewriteAssetImportsOptions,
): string | null {
  if (!isAssetSpecifier(source)) {
    return null;
  }

  if (source.startsWith('@/')) {
    const assetSpecifier = stripQueryAndHash(source);
    if (
      options.manifestAssetNames &&
      !options.manifestAssetNames.includes(assetSpecifier)
    ) {
      return null;
    }
    return assetSpecifier;
  }

  if (!source.startsWith('.')) {
    return null;
  }

  const relativeAssetPath = normalizeRelativeAssetPath(source);
  const expectedSpecifier = options.componentId
    ? `@/components/${options.componentId}/${relativeAssetPath}`
    : null;

  if (!options.manifestAssetNames) {
    return expectedSpecifier;
  }

  if (
    expectedSpecifier &&
    options.manifestAssetNames.includes(expectedSpecifier)
  ) {
    return expectedSpecifier;
  }

  if (expectedSpecifier && !hasParentDirectoryTraversal(source)) {
    return null;
  }

  const suffix = `/${relativeAssetPath}`;
  const matches = options.manifestAssetNames.filter((name) =>
    name.endsWith(suffix),
  );
  return matches.length === 1 ? matches[0] : null;
}

export function rewriteAssetImportsForCanvas(
  sourceCode: string,
  options: RewriteAssetImportsOptions = {},
): string {
  const importPattern =
    /^(\s*)import\s+([A-Za-z_$][\w$]*)\s+from\s+(['"])([^'"]+)\3\s*;?\s*$/gm;

  return sourceCode.replace(
    importPattern,
    (statement, indentation, identifier, _quote, source) => {
      const assetSpecifier = toCanvasAssetSpecifier(source, options);
      if (!assetSpecifier) {
        return statement;
      }

      return `${indentation}const ${identifier} = import.meta.resolve(${JSON.stringify(assetSpecifier)});`;
    },
  );
}
