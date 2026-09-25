import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  checkInstalledPackage,
  collectRuntimeExports,
  evaluateContextHookSupport,
  resolveRuntimeEntry,
} from './context-hook-support';

/** The shape the published package takes: chunks plus one export list. */
const BUILT_ENTRY = `import { a as warnOnce } from "./warnings-abc123.js";
import { getPageData } from "./drupal-utils.js";
import { useContext } from "react";
function usePageContext() {
  warnOnce("x");
  return useContext(null);
}
function useSiteContext() {
  return null;
}
export { getPageData, usePageContext, useSiteContext };
`;

async function writePackage(
  root: string,
  version: string,
  files: Record<string, string> = {
    'dist/index.js': BUILT_ENTRY,
    'dist/warnings-abc123.js':
      'function warnOnce() {}\nexport { warnOnce as a };\n',
    'dist/drupal-utils.js': 'export function getPageData() {}\n',
  },
  manifest: Record<string, unknown> = {
    exports: {
      './react': {
        import: './dist/index.js',
        types: './dist/index.d.ts',
        default: './dist/index.js',
      },
    },
  },
): Promise<void> {
  const dir = path.join(root, 'node_modules', 'drupal-canvas');
  await fs.rm(dir, { recursive: true, force: true });
  await fs.mkdir(path.join(dir, 'dist'), { recursive: true });
  await fs.writeFile(
    path.join(dir, 'package.json'),
    JSON.stringify({ name: 'drupal-canvas', version, ...manifest }),
  );
  await fs.writeFile(
    path.join(dir, 'dist', 'index.d.ts'),
    'export declare function usePageContext(): unknown;\nexport declare function useSiteContext(): unknown;\n',
  );
  for (const [file, source] of Object.entries(files)) {
    await fs.mkdir(path.dirname(path.join(dir, file)), { recursive: true });
    await fs.writeFile(path.join(dir, file), source);
  }
}

describe('context hook support', () => {
  let root: string;

  beforeEach(async () => {
    root = await fs.mkdtemp(path.join(os.tmpdir(), 'context-hooks-'));
    await fs.writeFile(path.join(root, 'package.json'), '{"name":"app"}');
  });

  afterEach(async () => {
    await fs.rm(root, { recursive: true, force: true });
  });

  it('rejects a root-only package even when its hooks exist', async () => {
    await writePackage(root, '99.0.0', undefined, {
      exports: { '.': './dist/index.js' },
    });
    expect((await checkInstalledPackage(root)).ok).toBe(false);
    const support = await evaluateContextHookSupport({
      siteUrl: 'https://example.test',
      projectRoot: root,
      fetchSiteData: async () => ({ capabilities: { contextHooks: true } }),
    });
    expect(support.supported).toBe(false);
    expect(support.reasons.join(' ')).toContain('drupal-canvas/react');
  });

  it('resolves the React runtime entry under the import conditions', () => {
    expect(
      resolveRuntimeEntry({
        exports: {
          './react': { import: './dist/index.js', default: './dist/x.js' },
        },
      }),
    ).toBe('./dist/index.js');
    expect(
      resolveRuntimeEntry({
        exports: {
          './react': {
            import: { types: './t.d.ts', default: './dist/esm.js' },
          },
        },
      }),
    ).toBe('./dist/esm.js');
    expect(resolveRuntimeEntry({ exports: './dist/only.js' })).toBe(null);
    expect(resolveRuntimeEntry({ module: './m.js', main: './c.js' })).toBe(
      null,
    );
    expect(
      resolveRuntimeEntry({ exports: { './react': { types: './t.d.ts' } } }),
    ).toBe(null);
  });

  it('respects condition order and a terminal denial of the React subpath', () => {
    // A null target for a matching condition denies the subpath; a later
    // condition is not a fallback.
    expect(
      resolveRuntimeEntry({
        exports: { './react': { default: null, import: './hooks.js' } },
      }),
    ).toBe(null);
    expect(
      resolveRuntimeEntry({
        exports: { './react': { import: null, default: './hooks.js' } },
      }),
    ).toBe(null);
    expect(resolveRuntimeEntry({ exports: { './react': null } })).toBe(null);
    // Object order decides between supported conditions.
    expect(
      resolveRuntimeEntry({
        exports: {
          './react': { default: './default.js', import: './import.js' },
        },
      }),
    ).toBe('./default.js');
    // Unsupported conditions are skipped, in order.
    expect(
      resolveRuntimeEntry({
        exports: {
          './react': {
            require: './cjs.js',
            node: { import: './node.js' },
            import: './import.js',
          },
        },
      }),
    ).toBe('./import.js');
    // A nested object without a match continues to the next condition.
    expect(
      resolveRuntimeEntry({
        exports: {
          './react': { import: { require: './x.js' }, default: './d.js' },
        },
      }),
    ).toBe('./d.js');
  });

  it('terminates on circular re-exports and reports them as unverifiable', async () => {
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export * from "./a.js";\nexport function usePageContext() {}\nexport function useSiteContext() {}\n',
      'dist/a.js': 'export * from "./index.js";\nexport const other = 1;\n',
    });
    const result = await Promise.race([
      checkInstalledPackage(root),
      new Promise<never>((_, reject) =>
        setTimeout(() => reject(new Error('hung')), 5000),
      ),
    ]);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('circularly');
    // Mutual named re-exports terminate too.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export { usePageContext, useSiteContext } from "./a.js";\n',
      'dist/a.js':
        'export { usePageContext, useSiteContext } from "./index.js";\n',
    });
    expect((await checkInstalledPackage(root)).reason).toContain('circularly');
  });

  it('rejects an unresolved export graph even when the hooks are found locally', async () => {
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export * from "./missing.js";\nexport function usePageContext() {}\nexport function useSiteContext() {}\n',
    });
    const result = await checkInstalledPackage(root);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('could not be verified');
    expect(result.reason).toContain('missing.js');
  });

  it('applies star export semantics: explicit wins, conflicting stars are ambiguous, one binding is not', async () => {
    // Two stars providing a hook from different bindings: ambiguous.
    await writePackage(root, '0.6.0', {
      'dist/index.js': 'export * from "./a.js";\nexport * from "./b.js";\n',
      'dist/a.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
      'dist/b.js': 'export const usePageContext = () => null;\n',
    });
    const conflicting = await checkInstalledPackage(root);
    expect(conflicting.ok).toBe(false);
    expect(conflicting.reason).toContain('`usePageContext()` ambiguously');
    // The same binding reached through two stars is one export.
    await writePackage(root, '0.6.0', {
      'dist/index.js': 'export * from "./a.js";\nexport * from "./b.js";\n',
      'dist/a.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
      'dist/b.js': 'export { usePageContext } from "./a.js";\n',
    });
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });
    // An explicit export shadows star exports of the same name.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export * from "./a.js";\nexport * from "./b.js";\nexport { hook as usePageContext } from "./c.js";\n',
      'dist/a.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
      'dist/b.js': 'export const usePageContext = () => null;\n',
      'dist/c.js': 'export function hook() {}\n',
    });
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });
    // A star that provides only unrelated names does not hide a missing hook.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export * from "./a.js";\nexport function usePageContext() {}\n',
      'dist/a.js': 'export const other = 1;\n',
    });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'does not export `useSiteContext()`',
    );
  });

  it('fails closed on named re-exports and imported aliases the source does not provide, even when a star supplies the hooks', async () => {
    // A named re-export of a name the source lacks: native linking fails.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export { absent as usePageContext } from "./empty.js";\nexport * from "./hooks.js";\n',
      'dist/empty.js': 'export const other = 1;\n',
      'dist/hooks.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
    });
    let result = await checkInstalledPackage(root);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('could not be verified');
    expect(result.reason).toContain('`absent`');
    // The same through an imported alias.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'import { absent } from "./empty.js";\nexport { absent as usePageContext };\nexport * from "./hooks.js";\n',
      'dist/empty.js': 'export const other = 1;\n',
      'dist/hooks.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
    });
    result = await checkInstalledPackage(root);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('does not export');
    // A default import from a module without a default export.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'import hook from "./named.js";\nexport { hook as usePageContext };\nexport function useSiteContext() {}\n',
      'dist/named.js': 'export const named = 1;\n',
    });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'could not be verified',
    );
    // A named re-export of a name the source exports ambiguously.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export { usePageContext } from "./both.js";\nexport { useSiteContext } from "./hooks.js";\n',
      'dist/both.js':
        'export * from "./hooks.js";\nexport * from "./other.js";\n',
      'dist/hooks.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
      'dist/other.js': 'export const usePageContext = () => null;\n',
    });
    result = await checkInstalledPackage(root);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('ambiguously');
    // Resolvable aliases keep working.
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'import { page } from "./hooks.js";\nexport { page as usePageContext };\nexport { site as useSiteContext } from "./hooks.js";\n',
      'dist/hooks.js': 'export function page() {}\nexport function site() {}\n',
    });
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });
  });

  it('accepts the built package format and local aliases and re-exports', async () => {
    await writePackage(root, '0.5.1');
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });

    await writePackage(root, '0.5.1', {
      'dist/index.js': `import { pageHook } from "./context.js";
export { pageHook as usePageContext };
export { site as useSiteContext } from "./site.js";
`,
      'dist/context.js': 'export const pageHook = () => null;\n',
      'dist/site.js': 'function site() {}\nexport { site };\n',
    });
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });

    await writePackage(root, '0.5.1', {
      'dist/index.js': 'export * from "./hooks/index.js";\n',
      'dist/hooks/index.js':
        'export function usePageContext() {}\nexport function useSiteContext() {}\n',
    });
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });
  });

  it('rejects declarations, type-only exports, comments and misleading names without runtime exports', async () => {
    // Declarations promise the hooks; the runtime entry exports nothing.
    await writePackage(root, '0.6.0', { 'dist/index.js': 'export {};\n' });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'does not export `usePageContext()` and `useSiteContext()` as runtime values',
    );
    await writePackage(root, '0.6.0', {
      'dist/index.js': `/* export { usePageContext, useSiteContext } */
function helper(usePageContext, useSiteContext) { return [usePageContext, useSiteContext]; }
const useSiteContextFactory = () => null;
export { helper, useSiteContextFactory };
`,
    });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'does not export',
    );
    // A package exporting TypeScript source: type-only exports are not
    // runtime values.
    await writePackage(
      root,
      '0.6.0',
      {
        'src/index.ts': `export type { usePageContext } from "./types";
export { type useSiteContext } from "./types";
`,
        'src/types.ts':
          'export const usePageContext = 1;\nexport const useSiteContext = 1;\n',
      },
      { exports: { './react': { import: './src/index.ts' } } },
    );
    expect((await checkInstalledPackage(root)).reason).toContain(
      'does not export',
    );
    // One of two is not enough.
    await writePackage(root, '0.6.0', {
      'dist/index.js': 'export function usePageContext() {}\n',
    });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'does not export `useSiteContext()`',
    );
  });

  it('fails closed with guidance when exports cannot be determined statically', async () => {
    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'module.exports = { usePageContext() {}, useSiteContext() {} };\n',
    });
    let result = await checkInstalledPackage(root);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('could not be verified');
    expect(result.reason).toContain('CommonJS');

    await writePackage(root, '0.6.0', {
      'dist/index.js':
        'export { usePageContext, useSiteContext } from "some-other-package";\n',
    });
    result = await checkInstalledPackage(root);
    expect(result.ok).toBe(false);
    expect(result.reason).toContain('could not be verified');

    await writePackage(root, '0.6.0', {
      'dist/index.js': 'export * from "./missing.js";\n',
    });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'could not be verified',
    );

    await writePackage(root, '0.6.0', { 'dist/index.js': 'export {' });
    expect((await checkInstalledPackage(root)).reason).toContain(
      'could not be parsed',
    );

    await writePackage(
      root,
      '0.6.0',
      { 'dist/index.js': BUILT_ENTRY },
      { exports: { './react': { types: './dist/index.d.ts' } } },
    );
    expect((await checkInstalledPackage(root)).reason).toContain(
      'declares no `drupal-canvas/react` entry',
    );
  });

  it('never follows re-exports outside the package', async () => {
    await writePackage(root, '0.6.0', {
      'dist/index.js': 'export * from "../../outside.js";\n',
    });
    await fs.writeFile(
      path.join(root, 'node_modules', 'outside.js'),
      'export function usePageContext() {}\nexport function useSiteContext() {}\n',
    );
    const packageRoot = path.join(root, 'node_modules', 'drupal-canvas');
    const exports = await collectRuntimeExports(
      path.join(packageRoot, 'dist', 'index.js'),
      packageRoot,
    );
    expect(exports.names.size).toBe(0);
    expect(exports.unverifiable[0]).toContain('re-exports everything');
  });

  it('requires the site to advertise context hooks', async () => {
    await writePackage(root, '0.6.0');
    const supported = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => ({ capabilities: { contextHooks: true } }),
    });
    expect(supported).toEqual({ supported: true, reasons: [] });

    const older = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => ({ baseUrl: 'https://drupal.example' }),
    });
    expect(older.supported).toBe(false);
    expect(older.reasons[0]).toContain('does not advertise');

    const unreachable = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => {
        throw new Error('ECONNREFUSED');
      },
    });
    expect(unreachable.supported).toBe(false);
    expect(unreachable.reasons[0]).toContain('could not be verified');
  });

  it('reports both limitations when both gates fail', async () => {
    const result = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => ({}),
    });
    expect(result.supported).toBe(false);
    expect(result.reasons).toHaveLength(2);
  });
});
