/**
 * @file
 * Maps the imports of discovered components to the entry files of the
 * components they import, for the analysis of their translatable strings.
 *
 * An import is resolved to the runtime file that it loads, which must be the
 * entry file of a discovered component to be followed. Resolution checks
 * whether files exist, but reads no other file than the entry files of
 * discovered components.
 */

import { existsSync, statSync } from 'node:fs';
import path from 'node:path';

// The extensions of the entry files whose strings can be analyzed.
const ANALYZED_EXTENSIONS = ['.ts', '.tsx', '.js', '.jsx'];

/**
 * The extensions that an import without one resolves to, in order, as Vite
 * resolves them by default.
 */
export const RUNTIME_EXTENSIONS = [
  '.mjs',
  '.js',
  '.mts',
  '.ts',
  '.jsx',
  '.tsx',
  '.json',
];

// The TypeScript files that imports with JavaScript extensions load when the
// JavaScript files do not exist, such as `./card.ts` for `./card.js`.
const TYPESCRIPT_SUBSTITUTES: Record<string, string[]> = {
  '.js': ['.ts', '.tsx'],
  '.jsx': ['.tsx'],
  '.mjs': ['.mts'],
  '.cjs': ['.cts'],
};

/**
 * The entry file of a discovered component.
 */
export interface RegisteredComponentModule {
  /** The path of the entry file. */
  id: string;
  /**
   * The source code, or `null` when it cannot be analyzed: the file is not
   * JavaScript or TypeScript, or cannot be read.
   */
  source: string | null;
}

/**
 * Resolves a non-relative import to the runtime file that it loads.
 *
 * @param specifier
 *   The specifier of the import.
 * @param importer
 *   The path of the module with the import.
 * @param check
 *   Tells whether a file exists, and records the check.
 *
 * @returns
 *   The path of the file, or `null` when the import resolves to none.
 */
export type AliasedImportResolver = (
  specifier: string,
  importer: string,
  check: (filePath: string) => boolean,
) => string | null;

export interface RegisteredComponentImports {
  /** Loads the entry file of a discovered component. */
  load: (entryPath: string) => RegisteredComponentModule;
  /**
   * Resolves the import of a discovered component, or returns `null` for an
   * import that is not followed.
   */
  resolve: (
    specifier: string,
    importer: string,
  ) => RegisteredComponentModule | null;
  /**
   * The files whose creation, change, or deletion can change the resolution
   * of the imports resolved so far: the files that resolution checked.
   */
  readonly checkedFiles: ReadonlySet<string>;
}

const isFile = (filePath: string): boolean => {
  try {
    return existsSync(filePath) && statSync(filePath).isFile();
  } catch {
    return false;
  }
};

/**
 * Resolves a path to the runtime file that it loads, as Vite does by default:
 * the file itself, the TypeScript file that a JavaScript extension stands for,
 * the path with an extension, then the `index` file of the directory. Imports
 * of directories with a `package.json` file resolve to none.
 *
 * @param target
 *   The absolute path, without a query or fragment.
 * @param check
 *   Tells whether a file exists.
 */
export function resolveRuntimeFile(
  target: string,
  check: (filePath: string) => boolean,
): string | null {
  const extension = path.extname(target);
  const file = [
    target,
    ...(TYPESCRIPT_SUBSTITUTES[extension] ?? []).map(
      (substitute) => target.slice(0, -extension.length) + substitute,
    ),
    ...RUNTIME_EXTENSIONS.map((runtimeExtension) => target + runtimeExtension),
  ].find((candidate) => check(candidate));
  if (file !== undefined) {
    return file;
  }
  // A directory with a `package.json` file loads the entry that the file
  // declares, which is not resolved.
  if (check(path.join(target, 'package.json'))) {
    return null;
  }
  return (
    RUNTIME_EXTENSIONS.map((runtimeExtension) =>
      path.join(target, `index${runtimeExtension}`),
    ).find((candidate) => check(candidate)) ?? null
  );
}

/**
 * Creates the mapping of imports to discovered components.
 *
 * Follows relative imports, and the non-relative ones that `resolveAliased`
 * resolves, when the file that they load is the entry file of a discovered
 * component. Imports with queries or fragments load files as data, and are
 * not followed.
 *
 * @param options.entryPaths
 *   The entry files of the discovered components.
 * @param options.readSource
 *   Reads an entry file, and throws when it cannot.
 * @param options.resolveAliased
 *   Resolves non-relative imports. Without it, only relative imports are
 *   followed.
 */
export function createRegisteredComponentImports(options: {
  entryPaths: string[];
  readSource: (entryPath: string) => string;
  resolveAliased?: AliasedImportResolver;
}): RegisteredComponentImports {
  const entries = new Map(
    options.entryPaths.map((entryPath) => [path.resolve(entryPath), entryPath]),
  );
  const checkedFiles = new Set<string>();
  const check = (filePath: string): boolean => {
    checkedFiles.add(filePath);
    return isFile(filePath);
  };
  const modules = new Map<string, RegisteredComponentModule>();
  const load = (entryPath: string): RegisteredComponentModule => {
    let module = modules.get(entryPath);
    if (!module) {
      let source: string | null = null;
      if (ANALYZED_EXTENSIONS.includes(path.extname(entryPath).toLowerCase())) {
        try {
          source = options.readSource(entryPath);
        } catch {
          source = null;
        }
      }
      module = { id: entryPath, source };
      modules.set(entryPath, module);
    }
    return module;
  };
  const resolve = (
    specifier: string,
    importer: string,
  ): RegisteredComponentModule | null => {
    // Queries and fragments, such as `?raw`, load files as data. A leading `#`
    // starts a subpath import or an alias instead.
    if (specifier.includes('?') || specifier.indexOf('#') > 0) {
      return null;
    }
    const file =
      specifier.startsWith('./') || specifier.startsWith('../')
        ? resolveRuntimeFile(
            path.resolve(path.dirname(importer), specifier),
            check,
          )
        : (options.resolveAliased?.(specifier, importer, check) ?? null);
    // A file that is not the entry file of a discovered component, such as a
    // helper, is not followed, also when it shadows one.
    const entryPath =
      file === null ? undefined : entries.get(path.resolve(file));
    return entryPath === undefined ? null : load(entryPath);
  };
  return { load, resolve, checkedFiles };
}
