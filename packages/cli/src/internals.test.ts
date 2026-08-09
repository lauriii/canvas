import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

import * as mainEntry from './internals.js';

/**
 * Names re-exported by an entry point, read from its source.
 *
 * The build entry point cannot be imported here: it pulls in Tailwind's
 * WebAssembly, which the test runner cannot load. Reading the re-export list
 * still catches the failure that matters, a name being removed or renamed, and
 * `npm run build` fails if any of those names does not resolve.
 */
function reExportedNames(file: string): string[] {
  const source = fs.readFileSync(path.join(import.meta.dirname, file), 'utf-8');
  const withoutTypes = source.replace(/export type \{[^}]*\}[^;]*;/g, '');
  return [...withoutTypes.matchAll(/export \{([^}]*)\}/g)]
    .flatMap((match) => match[1].split(','))
    .map((name) => name.trim())
    .filter(Boolean)
    .sort();
}

/**
 * Locks the shape of the published entry points.
 *
 * These are the only parts of this package other tools may depend on, so a name
 * disappearing or being renamed is a breaking change for them. This test exists
 * to make that impossible to do by accident: updating these lists should be a
 * deliberate act, accompanied by the right semver bump.
 *
 * Adding a name is backwards compatible. Removing or renaming one is not.
 */
const MAIN_ENTRY = [
  'ApiService',
  'discoverCanvasProject',
  'getConfig',
  'getDefaultScope',
  'getTokenEntry',
  'parseImportedJsComponents',
  'setConfig',
  'updateConfigFromOptions',
];

const BUILD_ENTRY = [
  'buildCanvasProject',
  'prepareGlobalAssetLibraryUpdate',
  'syncManifestArtifacts',
  'updateGlobalAssetLibraryForPush',
  'uploadComponents',
];

describe('@drupal-canvas/cli/internals', () => {
  it('exports exactly the documented surface', () => {
    expect(Object.keys(mainEntry).sort()).toStrictEqual(MAIN_ENTRY);
  });

  it('exports callables, not accidentally undefined re-exports', () => {
    for (const name of MAIN_ENTRY) {
      expect(
        typeof (mainEntry as unknown as Record<string, unknown>)[name],
      ).toBe('function');
    }
  });

  it('re-exports the documented names in its source too', () => {
    expect(reExportedNames('internals.ts')).toStrictEqual(MAIN_ENTRY);
  });

  it('keeps the component build out of the light entry point', () => {
    // Vite, Tailwind and their WebAssembly cost seconds to load. A consumer
    // that only talks to the Canvas API must not pay for them, so these live
    // behind `@drupal-canvas/cli/internals/build`.
    for (const heavy of BUILD_ENTRY) {
      expect(mainEntry).not.toHaveProperty(heavy);
    }
  });
});

describe('@drupal-canvas/cli/internals/build', () => {
  it('exports exactly the documented surface', () => {
    expect(reExportedNames('internals/build.ts')).toStrictEqual(BUILD_ENTRY);
  });
});
