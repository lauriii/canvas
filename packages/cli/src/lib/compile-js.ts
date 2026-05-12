import path from 'node:path';
import { ASSET_EXTENSIONS } from '@drupal-canvas/discovery';
import { transformSync } from '@swc/wasm';

import type { Options as SwcOptions } from '@swc/wasm';

// @see src/features/code-editor/hooks/useCompileJavaScript.ts
const SWC_OPTIONS: SwcOptions = {
  jsc: {
    parser: {
      syntax: 'typescript',
      tsx: true,
    },
    target: 'es2015',
    transform: {
      react: {
        pragmaFrag: 'Fragment',
        throwIfNamespace: true,
        development: false,
        runtime: 'automatic',
      },
    },
  },
  module: {
    type: 'es6',
  },
} as const;

export interface CompileJSOptions {
  filePath?: string;
  aliasBaseDir?: string;
}

function toPosixPath(value: string): string {
  return value.replaceAll('\\', '/');
}

function stripQueryAndHash(value: string): string {
  return value.split('?')[0].split('#')[0];
}

function isAssetSpecifier(value: string): boolean {
  if (value.includes('?react')) {
    return false;
  }
  const ext = path.extname(stripQueryAndHash(value)).toLowerCase();
  return (ASSET_EXTENSIONS as readonly string[]).includes(ext);
}

function toCanvasAssetSpecifier(
  source: string,
  filePath: string,
  aliasBaseDir: string,
): string | null {
  if (!isAssetSpecifier(source)) {
    return null;
  }

  if (source.startsWith('@/')) {
    return stripQueryAndHash(source);
  }

  if (!source.startsWith('.')) {
    return null;
  }

  const resolvedPath = path.resolve(path.dirname(filePath), source);
  const relativePath = path.relative(
    path.resolve(aliasBaseDir),
    stripQueryAndHash(resolvedPath),
  );

  if (relativePath.startsWith('..') || path.isAbsolute(relativePath)) {
    return null;
  }

  return `@/${toPosixPath(relativePath)}`;
}

function replaceAssetReferences(
  source: string,
  options: CompileJSOptions = {},
): string {
  if (!options.filePath || !options.aliasBaseDir) {
    return source;
  }

  const importPattern =
    /^(\s*)import\s+([A-Za-z_$][\w$]*)\s+from\s+(['"])([^'"]+)\3\s*;?\s*$/gm;
  const transformed = source.replace(
    importPattern,
    (statement, indentation, identifier, _quote, specifier) => {
      const assetSpecifier = toCanvasAssetSpecifier(
        specifier,
        options.filePath!,
        options.aliasBaseDir!,
      );
      if (!assetSpecifier) {
        return statement;
      }
      return `${indentation}const ${identifier} = import.meta.resolve(${JSON.stringify(assetSpecifier)});`;
    },
  );

  return transformed;
}

export function compileJS(source: string, options?: CompileJSOptions): string {
  const transformedSource = replaceAssetReferences(source, options);
  const { code } = transformSync(transformedSource, SWC_OPTIONS);
  return code;
}
