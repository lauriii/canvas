/**
 * @file
 * Resolves non-relative imports with the `paths` and `baseUrl` of the nearest
 * `tsconfig.json` or `jsconfig.json`, as frameworks that read them resolve
 * imports at runtime.
 */

import path from 'node:path';
import { createPathsMatcher, parseTsconfig } from 'get-tsconfig';

import { resolveRuntimeFile } from './registered-component-imports';

import type { AliasedImportResolver } from './registered-component-imports';

// In the same directory, `tsconfig.json` applies rather than `jsconfig.json`.
const CONFIG_NAMES = ['tsconfig.json', 'jsconfig.json'];

const isWithin = (filePath: string, root: string): boolean => {
  const relative = path.relative(root, filePath);
  return !relative.startsWith('..') && !path.isAbsolute(relative);
};

/**
 * Creates the resolution of non-relative imports with the configuration of
 * the project's `tsconfig.json` or `jsconfig.json` files.
 *
 * For each importing module, the nearest of those files in its directory or a
 * parent directory up to the project root applies, with the configuration it
 * extends; changes to the configuration it extends apply to new instances.
 * Files outside the project root do not apply. Each candidate path of
 * the matching `paths` pattern, or of `baseUrl`, resolves to the first
 * existing runtime file.
 *
 * @param options.projectRoot
 *   The root of the project.
 */
export function createConfigAliasedImports(options: {
  projectRoot: string;
}): AliasedImportResolver {
  const projectRoot = path.resolve(options.projectRoot);
  const matchers = new Map<
    string,
    ReturnType<typeof createPathsMatcher> | null
  >();
  const parseCache = new Map<string, string>();

  const getMatcher = (
    directory: string,
    check: (filePath: string) => boolean,
  ): ReturnType<typeof createPathsMatcher> | null => {
    if (matchers.has(directory)) {
      return matchers.get(directory)!;
    }
    let matcher: ReturnType<typeof createPathsMatcher> | null = null;
    const configPath = CONFIG_NAMES.map((name) =>
      path.join(directory, name),
    ).find((candidate) => check(candidate));
    if (configPath) {
      try {
        matcher = createPathsMatcher({
          path: configPath,
          config: parseTsconfig(configPath, parseCache),
        });
      } catch {
        // The application's own build reports invalid configuration.
        matcher = null;
      }
    } else if (directory !== projectRoot) {
      matcher = getMatcher(path.dirname(directory), check);
    }
    matchers.set(directory, matcher);
    return matcher;
  };

  return (specifier, importer, check) => {
    const directory = path.dirname(path.resolve(importer));
    if (!isWithin(directory, projectRoot)) {
      return null;
    }
    const matcher = getMatcher(directory, check);
    if (!matcher) {
      return null;
    }
    for (const candidate of matcher(specifier)) {
      const file = resolveRuntimeFile(candidate, check);
      if (file !== null) {
        return file;
      }
    }
    return null;
  };
}
