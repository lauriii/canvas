/**
 * @file
 *
 * Finds the strings a component translates in its own source code and in the
 * components that it imports, which it renders itself.
 *
 * The caller resolves the imports: which of them are components, and their
 * source code. Other imports are not followed.
 */

import { analyzeModule, normalizeTranslatableStrings } from './extract';

import type { TranslatableString, TranslatableStringError } from './extract';

/**
 * The entry file of a component.
 */
export interface ComponentModule {
  /** An ID of the entry file, such as its path. */
  id: string;
  /**
   * The source code, or `null` when it cannot be analyzed: the file is not
   * JavaScript or TypeScript, or cannot be read.
   */
  source: string | null;
}

/**
 * Resolves the static import of a component.
 *
 * @param specifier
 *   The specifier of the import.
 * @param importer
 *   The ID of the module with the import.
 *
 * @returns
 *   The component's entry file, or `null` for an import that is not followed.
 */
export type ComponentImportResolver = (
  specifier: string,
  importer: string,
) => ComponentModule | null;

export interface ModuleTranslatableStringError extends TranslatableStringError {
  // The ID of the module with the literal.
  module: string;
}

export type ComponentTranslatableStrings = {
  /**
   * Whether the component or a component it imports refers to
   * `useTranslation()`, or `null` when that is unknown because a module
   * cannot be analyzed.
   */
  usesTranslationHook: boolean | null;
  /**
   * The IDs of the component's entry file and of the entry files of the
   * components it imports, which were analyzed. A change to one of them can
   * change the result.
   */
  modules: string[];
} & (
  | { translatableStrings: TranslatableString[] }
  // Invalid literals, of the component first.
  | { errors: ModuleTranslatableStringError[] }
  // The strings are unknown: a module cannot be analyzed.
  | {
      unknown: {
        module: string;
        // When the module cannot be parsed.
        syntaxError?: TranslatableStringError;
      };
    }
);

/**
 * Finds the strings of a component's `t()` and `formatPlural()` calls.
 *
 * Follows the components that the component imports statically, directly or
 * through other components, including cycles, and merges their strings,
 * deduplicated and sorted. Only the calls through `useTranslation()` in each
 * module are found, as in the component itself.
 *
 * @param entry
 *   The component's entry file.
 * @param resolve
 *   Resolves the imports of components.
 */
export const getComponentTranslatableStrings = (
  entry: ComponentModule,
  resolve: ComponentImportResolver,
): ComponentTranslatableStrings => {
  const strings: TranslatableString[] = [];
  const errors: ModuleTranslatableStringError[] = [];
  let unknown:
    | Extract<ComponentTranslatableStrings, { unknown: unknown }>['unknown']
    | undefined;
  let usesTranslationHook = false;
  const visited = new Set([entry.id]);
  const queue = [entry];
  for (let module = queue.shift(); module; module = queue.shift()) {
    if (module.source === null) {
      unknown ??= { module: module.id };
      continue;
    }
    const analysis = analyzeModule(module.source);
    if ('syntaxError' in analysis) {
      unknown ??= { module: module.id, syntaxError: analysis.syntaxError };
      continue;
    }
    strings.push(...analysis.translatableStrings);
    errors.push(
      ...analysis.errors.map((error) => ({ ...error, module: module!.id })),
    );
    usesTranslationHook ||= analysis.usesTranslationHook;
    for (const specifier of analysis.imports) {
      const resolved = resolve(specifier, module.id);
      if (resolved && !visited.has(resolved.id)) {
        visited.add(resolved.id);
        queue.push(resolved);
      }
    }
  }
  const common = {
    usesTranslationHook: usesTranslationHook || (unknown ? null : false),
    modules: [...visited],
  };
  if (errors.length > 0) {
    return { ...common, errors };
  }
  if (unknown) {
    return { ...common, unknown };
  }
  return {
    ...common,
    translatableStrings: normalizeTranslatableStrings(strings),
  };
};
