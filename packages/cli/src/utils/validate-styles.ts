import { promises as fs } from 'node:fs';
import path from 'path';
import { compileCss } from 'tailwindcss-in-browser';

import {
  findStyleLibraryCollisions,
  formatStyleLibraryDiagnostic,
  readLocalStyleLibraries,
} from '../lib/styles/style-library';
import {
  definitionsFromStyleLibrary,
  mergeStyleDefinitions,
  partStyleIds,
  readStylesSnapshot,
} from '../lib/styles/style-outputs';
import { getGlobalCss } from './build-tailwind';
import { readComponentMetadata } from './process-component-files';

import type { DiscoveryResult } from '@drupal-canvas/discovery';
import type { LocalStyleLibrary } from '../lib/styles/style-library';
import type { StyleDefinitionSummary } from '../lib/styles/style-outputs';
import type { Result } from '../types/Result';

export const RESERVED_STYLE_PROP_GUIDE =
  'https://project.pages.drupalcode.org/canvas/code-components/styles#rename-a-reserved-prop';
const RESERVED_STYLE_PROP_NAMES = ['className', 'style', 'styleParts'];

export interface ComponentStyleFindings {
  errors: string[];
  warnings: string[];
}

/**
 * Whether component source reads the `className` prop. JSX attributes such
 * as `className="…"` do not count. This is a heuristic: forwarding wrappers
 * can hide the prop, so a miss is reported as a hint.
 */
export function referencesClassNameProp(source: string): boolean {
  return /\bclassName\b(?!\s*=)/.test(source);
}

function escapeCssClass(name: string): string {
  return name.replace(/[^a-zA-Z0-9_-]/g, (char) => `\\${char}`);
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Returns the classes the Tailwind build emits no rule for. Classes defined
 * by hand in global CSS count as built.
 */
export async function findClassesWithoutCss(
  classes: string[],
  globalCss: string,
): Promise<string[]> {
  if (classes.length === 0) {
    return [];
  }
  const css = await compileCss(classes, globalCss);
  return classes.filter(
    (name) =>
      !new RegExp(`\\.${escapeRegExp(escapeCssClass(name))}(?![\\w-])`).test(
        css,
      ),
  );
}

function optionClassesByLibrary(
  libraries: LocalStyleLibrary[],
): Map<string, { library: LocalStyleLibrary; option: string }[]> {
  const byClass = new Map<
    string,
    { library: LocalStyleLibrary; option: string }[]
  >();
  for (const library of libraries) {
    for (const definition of definitionsFromStyleLibrary(library.library)) {
      for (const option of definition.options) {
        if (option.attribute !== 'class') continue;
        for (const name of option.value.split(/\s+/).filter(Boolean)) {
          byClass.set(name, [
            ...(byClass.get(name) ?? []),
            { library, option: `${definition.id}.${option.id}` },
          ]);
        }
      }
    }
  }
  return byClass;
}

async function validateLibraries(
  libraries: LocalStyleLibrary[],
  snapshot: StyleDefinitionSummary[],
): Promise<Result[]> {
  const collisions = findStyleLibraryCollisions(libraries, snapshot);
  const extraWarnings = new Map<LocalStyleLibrary, string[]>();

  // Option classes that produce no CSS, when the project has global CSS to
  // build with. Only Canvas-managed libraries are checked: theme classes
  // are built by the theme.
  let globalCss: string | null = null;
  try {
    globalCss = await getGlobalCss();
  } catch {
    // No global CSS: the build itself reports this.
  }
  if (globalCss !== null) {
    const byClass = optionClassesByLibrary(libraries);
    try {
      for (const name of await findClassesWithoutCss(
        [...byClass.keys()],
        globalCss,
      )) {
        for (const { library, option } of byClass.get(name) ?? []) {
          extraWarnings.set(library, [
            ...(extraWarnings.get(library) ?? []),
            `The class "${name}" of option ${option} produces no CSS in the Tailwind build. Check the spelling, or define it in global CSS.`,
          ]);
        }
      }
    } catch {
      // The Tailwind build reports its own errors.
    }
  }

  return libraries.map((library) => {
    const errors = [
      ...library.diagnostics
        .filter((d) => d.severity === 'error')
        .map(formatStyleLibraryDiagnostic),
      ...(collisions.get(library) ?? []),
    ];
    const warnings = [
      ...library.diagnostics
        .filter((d) => d.severity === 'warning')
        .map(formatStyleLibraryDiagnostic),
      ...(extraWarnings.get(library) ?? []),
    ];
    return {
      itemName: path.relative(process.cwd(), library.filePath),
      itemType: 'Style library',
      success: errors.length === 0,
      ...(errors.length > 0 && { details: [{ content: errors.join('\n') }] }),
      ...(warnings.length > 0 && { warnings }),
    };
  });
}

/**
 * Validates style usage: local library rules, unknown or colliding style
 * IDs, reserved prop names, and hints that never block.
 */
export async function validateStyles(
  discoveryResult: Pick<DiscoveryResult, 'components'>,
  options: { projectRoot: string; stylesDir: string },
): Promise<{
  libraryResults: Result[];
  componentFindings: Map<string, ComponentStyleFindings>;
}> {
  const libraries =
    (await readLocalStyleLibraries(
      path.resolve(options.projectRoot, options.stylesDir),
    )) ?? [];
  const snapshot = await readStylesSnapshot(options.projectRoot);
  const definitions = mergeStyleDefinitions(
    ...libraries.map((l) => definitionsFromStyleLibrary(l.library)),
    snapshot,
  );
  const known = new Set(definitions.map((d) => d.id));
  const libraryResults = await validateLibraries(libraries, snapshot);

  const componentFindings = new Map<string, ComponentStyleFindings>();
  const seenDirectories = new Set<string>();
  for (const component of discoveryResult.components) {
    if (seenDirectories.has(component.directory)) continue;
    seenDirectories.add(component.directory);
    let metadata;
    try {
      metadata = await readComponentMetadata(component.metadataPath);
    } catch {
      // Component validation reports invalid metadata.
      continue;
    }
    const errors: string[] = [];
    const warnings: string[] = [];
    const accepted = metadata.styles;
    const parts = Object.values(metadata.styleParts ?? {});
    const rootAcceptsStyles =
      accepted === '*' || (Array.isArray(accepted) && accepted.length > 0);
    const acceptsStyles =
      rootAcceptsStyles || parts.some((part) => partStyleIds(part).length > 0);
    const props = metadata.props?.properties ?? {};

    const listed = [
      ...(Array.isArray(accepted) ? accepted : []),
      ...parts.flatMap(partStyleIds),
    ];
    if (listed.length > 0) {
      const unknown = [...new Set(listed)].filter((id) => !known.has(id));
      if (unknown.length > 0) {
        warnings.push(
          `component.yml lists ${unknown.length === 1 ? 'a style' : 'styles'} that ${unknown.length === 1 ? 'is' : 'are'} neither in ${options.stylesDir}/ nor in .canvas/styles.json: ${unknown.join(', ')}. Run \`canvas pull\` to refresh the snapshot, or check the spelling.`,
        );
      }
    }

    if (acceptsStyles) {
      for (const name of RESERVED_STYLE_PROP_NAMES) {
        if (name in props) {
          errors.push(
            `The prop "${name}" is reserved for components that accept styles: Canvas passes the selected styles as \`${name}\`. Rename the prop. See ${RESERVED_STYLE_PROP_GUIDE}`,
          );
        }
      }
      // Parts receive `styleParts` instead, so only root styles need it.
      if (rootAcceptsStyles && component.jsEntryPath) {
        try {
          const source = await fs.readFile(component.jsEntryPath, 'utf-8');
          if (!referencesClassNameProp(source)) {
            warnings.push(
              "The component accepts styles but never references `className`. Apply it on the root element, for example `className={cn('…', className)}`, along with `style`.",
            );
          }
        } catch {
          // Unreadable source is reported by the build.
        }
      }
    }

    for (const [propName, prop] of Object.entries(props)) {
      const values = (prop as { enum?: unknown }).enum;
      if (
        !Array.isArray(values) ||
        values.length < 2 ||
        !values.every((value) => typeof value === 'string')
      ) {
        continue;
      }
      const match = definitions.find((definition) => {
        const optionIds = new Set(definition.options.map((o) => o.id));
        return values.every((value) => optionIds.has(value as string));
      });
      if (match) {
        warnings.push(
          `The prop "${propName}" has the values of the style "${match.id}" (${values.join(', ')}). If it only changes the look, accept the style instead. Keep the prop if the variant is specific to this component.`,
        );
      }
    }

    if (errors.length > 0 || warnings.length > 0) {
      componentFindings.set(component.name, { errors, warnings });
    }
  }

  return { libraryResults, componentFindings };
}

/** Adds style findings to component validation results. */
export function mergeComponentStyleFindings(
  results: Result[],
  findings: Map<string, ComponentStyleFindings>,
): Result[] {
  return results.map((result) => {
    const finding = findings.get(result.itemName);
    if (!finding) {
      return result;
    }
    return {
      ...result,
      success: result.success && finding.errors.length === 0,
      details: [
        ...(result.details ?? []),
        ...(finding.errors.length > 0
          ? [{ heading: 'Styles', content: finding.errors.join('\n') }]
          : []),
      ],
      warnings: [...(result.warnings ?? []), ...finding.warnings],
    };
  });
}
