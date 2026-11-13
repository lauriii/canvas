/**
 * @file
 * The translatable strings of discovered components, analyzed as the Headless
 * SDK analyzes them.
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';
import {
  createRegisteredComponentImports,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';
import { getComponentTranslatableStrings } from '@drupal-canvas/translatable-strings';
import { resolveHostImportPath } from '@drupal-canvas/vite-compat';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { PreviewManifestComponentTranslation } from '../lib/preview-contract';

export interface ComponentTranslationAnalysis {
  translation: PreviewManifestComponentTranslation;
  /**
   * The entry files of the component and of the components it imports, whose
   * changes can change the translation metadata.
   */
  modules: string[];
  /**
   * The files that the resolution of imports checked, whose creation or
   * deletion can change the translation metadata.
   */
  checkedFiles: string[];
}

export interface RegisteredComponentsOptions {
  projectRoot: string;
  /** The entry files of the registered components. */
  entryPaths: string[];
}

/**
 * Creates the analysis of the translatable strings of registered components.
 *
 * Follows the registered components that a component imports, which it
 * renders itself, but not other local modules. Imports resolve as previews
 * load them: relative ones, and `@/` ones with the aliases of the Canvas
 * configuration.
 *
 * @returns
 *   Analyzes the entry file of a registered component.
 */
export function createComponentTranslationAnalysis(
  options: RegisteredComponentsOptions,
): (jsEntryPath: string | null) => ComponentTranslationAnalysis {
  const relative = (filePath: string) =>
    path.relative(options.projectRoot, filePath).replaceAll('\\', '/');
  let aliasBaseDir: string | null;
  try {
    aliasBaseDir = resolveCanvasConfig({
      hostRoot: options.projectRoot,
    }).aliasBaseDir;
  } catch {
    // Previews report invalid configuration.
    aliasBaseDir = null;
  }
  const componentImports = createRegisteredComponentImports({
    entryPaths: options.entryPaths,
    readSource: (entryPath) => readFileSync(entryPath, 'utf-8'),
    resolveAliased: (specifier, _importer, check) => {
      if (aliasBaseDir === null || !specifier.startsWith('@/')) {
        return null;
      }
      let file: string;
      try {
        file = resolveHostImportPath(
          options.projectRoot,
          aliasBaseDir,
          specifier.slice('@/'.length),
        );
      } catch {
        return null;
      }
      return check(file) ? file : null;
    },
  });
  return (jsEntryPath) => {
    if (!jsEntryPath) {
      return {
        translation: {
          usesTranslationHook: null,
          translatableStrings: null,
          issues: [],
        },
        modules: [],
        checkedFiles: [],
      };
    }
    const result = getComponentTranslatableStrings(
      componentImports.load(jsEntryPath),
      componentImports.resolve,
    );
    let translation: PreviewManifestComponentTranslation;
    if ('translatableStrings' in result) {
      translation = {
        usesTranslationHook: result.usesTranslationHook,
        translatableStrings: result.translatableStrings,
      };
    } else if ('errors' in result) {
      // Invalid literals make the strings unknown, as when the Canvas CLI fails
      // to build the component.
      translation = {
        usesTranslationHook: result.usesTranslationHook,
        translatableStrings: null,
        issues: result.errors.map(({ module, line, column, message }) => ({
          path: relative(module),
          line,
          column,
          message,
        })),
      };
    } else {
      const { module, syntaxError } = result.unknown;
      translation = {
        usesTranslationHook: result.usesTranslationHook,
        translatableStrings: null,
        issues: [
          syntaxError
            ? {
                path: relative(module),
                line: syntaxError.line,
                column: syntaxError.column,
                message: syntaxError.message,
              }
            : // Workbench discovers JavaScript and TypeScript entry files only.
              { path: relative(module), message: 'Cannot read the file.' },
        ],
      };
    }
    return {
      translation,
      modules: result.modules,
      checkedFiles: [...componentImports.checkedFiles],
    };
  };
}

/**
 * Analyzes the translatable strings of every discovered component.
 *
 * @returns
 *   The analyses, positionally parallel to the discovered components.
 */
export function analyzeComponentTranslations(
  discoveryResult: DiscoveryResult,
): ComponentTranslationAnalysis[] {
  const analyze = createComponentTranslationAnalysis({
    projectRoot: discoveryResult.projectRoot,
    entryPaths: discoveryResult.components.flatMap(({ jsEntryPath }) =>
      jsEntryPath ? [jsEntryPath] : [],
    ),
  });
  return discoveryResult.components.map(({ jsEntryPath }) =>
    analyze(jsEntryPath),
  );
}

/**
 * Tracks the interface translation metadata of components across changes.
 */
export interface ComponentTranslationTracker {
  /** Analyzes the components again, and tells whether the metadata changed. */
  update(): { analyses: ComponentTranslationAnalysis[]; changed: boolean };
  /**
   * Whether a change to a file can change the metadata, as of the last
   * analysis: the entry file of an analyzed component, or a file that the
   * resolution of imports checked.
   */
  dependsOn(filePath: string): boolean;
  /** The files to watch for those changes, as of the last analysis. */
  readonly watchPaths: string[];
  /** The revision of the metadata, which changes with it. */
  readonly revision: number;
}

export function createComponentTranslationTracker(
  analyze: () => ComponentTranslationAnalysis[],
): ComponentTranslationTracker {
  let key = '';
  let modules = new Set<string>();
  let checkedFiles = new Set<string>();
  let revision = 0;
  return {
    update() {
      const analyses = analyze();
      const nextKey = JSON.stringify(
        analyses.map(({ translation }) => translation),
      );
      const changed = nextKey !== key;
      key = nextKey;
      modules = new Set(
        analyses.flatMap((analysis) =>
          analysis.modules.map((module) => path.resolve(module)),
        ),
      );
      checkedFiles = new Set(
        analyses.flatMap((analysis) =>
          analysis.checkedFiles.map((file) => path.resolve(file)),
        ),
      );
      revision += changed ? 1 : 0;
      return { analyses, changed };
    },
    dependsOn: (filePath) => {
      const resolved = path.resolve(filePath);
      // A path that an alias resolves to without an extension stands for the
      // files with one, and for the `index` files of the directory.
      const withoutExtension = resolved.slice(
        0,
        resolved.length - path.extname(resolved).length,
      );
      return (
        modules.has(resolved) ||
        checkedFiles.has(resolved) ||
        checkedFiles.has(withoutExtension) ||
        (path.basename(withoutExtension) === 'index' &&
          checkedFiles.has(path.dirname(resolved)))
      );
    },
    get watchPaths() {
      return [...checkedFiles];
    },
    get revision() {
      return revision;
    },
  };
}
