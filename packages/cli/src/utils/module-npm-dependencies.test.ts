import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  checkInstalledNpmDependencies,
  mergeNpmDependencies,
} from './module-npm-dependencies';

const tempDirs: string[] = [];
afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

describe('mergeNpmDependencies', () => {
  it('adds a missing package and records that it did', () => {
    const before = `{\n  "name": "p",\n  "dependencies": {\n    "zod": "^4.0.0",\n    "react": "^19.0.0"\n  }\n}\n`;
    const result = mergeNpmDependencies(before, {
      '@acme/canvas-forms': '1.2.0',
    });
    expect(result.added).toEqual(['@acme/canvas-forms']);
    const next = JSON.parse(result.text as string);
    // Appended, not sorted: the developer's order is theirs.
    expect(Object.keys(next.dependencies)).toEqual([
      'zod',
      'react',
      '@acme/canvas-forms',
    ]);
    expect(next.canvas).toEqual({
      npmDependencies: { '@acme/canvas-forms': '1.2.0' },
    });
    // Indentation and the trailing newline are kept.
    expect(result.text).toMatch(/^\{\n {2}"name"/);
    expect(result.text?.endsWith('}\n')).toBe(true);
  });

  it('preserves tab indentation', () => {
    const before = `{\n\t"name": "p"\n}`;
    const result = mergeNpmDependencies(before, { a: '1.0.0' });
    expect(result.text).toMatch(/^\{\n\t"name"/);
    expect(result.text?.endsWith('\n')).toBe(false);
  });

  describe('round trip: a file that already has the declared packages is untouched', () => {
    const declared = { '@acme/canvas-forms': '1.2.0' };
    it.each([
      [
        'exact version, no record',
        { dependencies: { '@acme/canvas-forms': '1.2.0', react: '^19' } },
      ],
      [
        'range that allows the declared version',
        { dependencies: { '@acme/canvas-forms': '^1.2.0' } },
      ],
      [
        'in devDependencies',
        { devDependencies: { '@acme/canvas-forms': '1.2.0' } },
      ],
      [
        'in peerDependencies, as a range',
        { peerDependencies: { '@acme/canvas-forms': '>=1.0.0' } },
      ],
      [
        'added by an earlier pull, record in place',
        {
          dependencies: { '@acme/canvas-forms': '1.2.0' },
          canvas: { npmDependencies: { '@acme/canvas-forms': '1.2.0' } },
        },
      ],
      [
        'removed by the developer after an earlier pull added it',
        {
          dependencies: { react: '^19' },
          canvas: { npmDependencies: { '@acme/canvas-forms': '1.2.0' } },
        },
      ],
    ])('%s', (_label, json) => {
      const pushed = `${JSON.stringify(json, null, 2)}\n`;
      // text === null means no write at all: what was pushed is what a pull
      // gives back, byte for byte.
      expect(mergeNpmDependencies(pushed, declared).text).toBeNull();
    });
  });

  it('leaves a developer-set value alone and reports the disagreement', () => {
    const before = JSON.stringify({
      dependencies: { '@acme/canvas-forms': 'file:../module/js' },
    });
    const result = mergeNpmDependencies(before, {
      '@acme/canvas-forms': '1.2.0',
    });
    expect(result.text).toBeNull();
    expect(result.conflicts).toEqual([
      {
        name: '@acme/canvas-forms',
        declared: '1.2.0',
        current: 'file:../module/js',
      },
    ]);
  });

  it('does not report a range the declared version satisfies', () => {
    for (const range of [
      '^1.2.0',
      '~1.2.0',
      '>=1.2.0',
      '1.x',
      '1.2.0 - 1.4.0',
    ]) {
      const before = JSON.stringify({
        dependencies: { '@acme/canvas-forms': range },
      });
      const result = mergeNpmDependencies(before, {
        '@acme/canvas-forms': '1.2.0',
      });
      expect(result.text, range).toBeNull();
      expect(result.conflicts, range).toEqual([]);
    }
  });

  it('reports a range that excludes the declared version, and non-ranges', () => {
    for (const spec of [
      '>1.2.0',
      '^2.0.0',
      'file:../1.2.0',
      'latest',
      'git+https://x/y#1.2.0',
    ]) {
      const result = mergeNpmDependencies(
        JSON.stringify({ dependencies: { '@acme/canvas-forms': spec } }),
        { '@acme/canvas-forms': '1.2.0' },
      );
      expect(result.conflicts, spec).toEqual([
        { name: '@acme/canvas-forms', declared: '1.2.0', current: spec },
      ]);
    }
  });

  it('sees a package in any dependency section and never duplicates it', () => {
    const before = JSON.stringify({
      devDependencies: { '@acme/canvas-forms': '1.2.0' },
      peerDependencies: { react: '^18.0.0' },
    });
    const result = mergeNpmDependencies(before, {
      '@acme/canvas-forms': '1.2.0',
      react: '19.0.0',
    });
    // Nothing to add, nothing written; the excluding peer range is reported.
    expect(result.text).toBeNull();
    expect(result.added).toEqual([]);
    expect(result.conflicts).toEqual([
      { name: 'react', declared: '19.0.0', current: '^18.0.0' },
    ]);
  });

  it('does not rewrite a version it wrote when the declaration moves on; it reports it', () => {
    const before = JSON.stringify({
      dependencies: { '@acme/canvas-forms': '1.2.0' },
      canvas: { npmDependencies: { '@acme/canvas-forms': '1.2.0' } },
    });
    const result = mergeNpmDependencies(before, {
      '@acme/canvas-forms': '1.3.0',
    });
    const next = JSON.parse(result.text as string);
    // The dependency is the developer's to move; the record tracks the site.
    expect(next.dependencies).toEqual({ '@acme/canvas-forms': '1.2.0' });
    expect(next.canvas.npmDependencies).toEqual({
      '@acme/canvas-forms': '1.3.0',
    });
    expect(result.conflicts).toEqual([
      { name: '@acme/canvas-forms', declared: '1.3.0', current: '1.2.0' },
    ]);
  });

  it('does not re-add a package the developer removed, on this pull or the next', () => {
    const before = JSON.stringify({
      dependencies: { react: '^19.0.0' },
      canvas: { npmDependencies: { '@acme/canvas-forms': '1.2.0' } },
    });
    const first = mergeNpmDependencies(before, {
      '@acme/canvas-forms': '1.2.0',
    });
    expect(first.removedByDeveloper).toEqual(['@acme/canvas-forms']);
    // Nothing changed, so nothing written, and the record still remembers.
    expect(first.text).toBeNull();
    // Even once the declaration moves on, the package stays out.
    const second = mergeNpmDependencies(before, {
      '@acme/canvas-forms': '1.3.0',
    });
    expect(second.added).toEqual([]);
    expect(JSON.parse(second.text as string).dependencies).toEqual({
      react: '^19.0.0',
    });
    expect(JSON.parse(second.text as string).canvas.npmDependencies).toEqual({
      '@acme/canvas-forms': '1.3.0',
    });
  });

  it('never removes a dependency when the site stops declaring it', () => {
    const before = JSON.stringify({
      dependencies: { '@acme/canvas-forms': '1.2.0', react: '^19.0.0' },
      canvas: { npmDependencies: { '@acme/canvas-forms': '1.2.0' } },
    });
    const result = mergeNpmDependencies(before, {});
    const next = JSON.parse(result.text as string);
    expect(result.noLongerDeclared).toEqual(['@acme/canvas-forms']);
    // Still there: a component may still import it.
    expect(next.dependencies).toEqual({
      '@acme/canvas-forms': '1.2.0',
      react: '^19.0.0',
    });
    expect(next.canvas).toBeUndefined();
  });

  it('keeps unrelated keys under canvas', () => {
    const before = JSON.stringify({ canvas: { other: true } });
    const next = JSON.parse(
      mergeNpmDependencies(before, { a: '1.0.0' }).text as string,
    );
    expect(next.canvas).toEqual({
      other: true,
      npmDependencies: { a: '1.0.0' },
    });
  });
});

describe('checkInstalledNpmDependencies', () => {
  async function project(
    packageJson: object,
    installed: Record<string, string>,
  ): Promise<string> {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-npm-deps-'));
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, 'package.json'),
      JSON.stringify(packageJson),
    );
    for (const [name, version] of Object.entries(installed)) {
      const dir = path.join(root, 'node_modules', ...name.split('/'));
      await fs.mkdir(dir, { recursive: true });
      await fs.writeFile(
        path.join(dir, 'package.json'),
        JSON.stringify({ name, version }),
      );
    }
    return root;
  }

  const recorded = {
    dependencies: { '@acme/a': '1.0.0', '@acme/b': '1.0.0', react: '^19' },
    canvas: { npmDependencies: { '@acme/a': '1.0.0', '@acme/b': '1.0.0' } },
  };

  it('reports missing and mismatched packages that components import', async () => {
    const root = await project(recorded, { '@acme/a': '1.1.0' });
    await expect(
      checkInstalledNpmDependencies(root, ['@acme/a', '@acme/b/sub', 'react']),
    ).resolves.toEqual({
      missing: [{ name: '@acme/b', declared: '1.0.0' }],
      mismatched: [{ name: '@acme/a', declared: '1.0.0', installed: '1.1.0' }],
    });
  });

  it('ignores recorded packages nothing imports, so a stale record cannot fail a build', async () => {
    const root = await project(recorded, {});
    await expect(
      checkInstalledNpmDependencies(root, ['react']),
    ).resolves.toEqual({ missing: [], mismatched: [] });
  });

  it('finds a package hoisted to a parent node_modules', async () => {
    // A workspace project: the package is installed one level up.
    const root = await project(
      {
        dependencies: { '@acme/a': '1.0.0' },
        canvas: { npmDependencies: { '@acme/a': '1.0.0' } },
      },
      {},
    );
    const workspace = path.join(root, 'packages', 'site');
    await fs.mkdir(workspace, { recursive: true });
    await fs.rename(
      path.join(root, 'package.json'),
      path.join(workspace, 'package.json'),
    );
    const hoisted = path.join(root, 'node_modules', '@acme', 'a');
    await fs.mkdir(hoisted, { recursive: true });
    await fs.writeFile(
      path.join(hoisted, 'package.json'),
      JSON.stringify({ name: '@acme/a', version: '1.0.0', main: 'index.js' }),
    );
    await fs.writeFile(path.join(hoisted, 'index.js'), '');
    await expect(
      checkInstalledNpmDependencies(workspace, ['@acme/a']),
    ).resolves.toEqual({ missing: [], mismatched: [] });
  });

  it('reads the version of a package whose exports hide package.json', async () => {
    const root = await project(
      {
        dependencies: { sealed: '2.0.0' },
        canvas: { npmDependencies: { sealed: '2.0.0' } },
      },
      {},
    );
    const dir = path.join(root, 'node_modules', 'sealed');
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(
      path.join(dir, 'package.json'),
      JSON.stringify({
        name: 'sealed',
        version: '2.1.0',
        exports: { '.': './index.js' },
      }),
    );
    await fs.writeFile(path.join(dir, 'index.js'), '');
    await expect(
      checkInstalledNpmDependencies(root, ['sealed']),
    ).resolves.toEqual({
      missing: [],
      mismatched: [{ name: 'sealed', declared: '2.0.0', installed: '2.1.0' }],
    });
  });

  it('finds an ESM-only package whose exports expose only an import condition', async () => {
    const root = await project(
      {
        dependencies: { 'esm-only': '3.0.0' },
        canvas: { npmDependencies: { 'esm-only': '3.0.0' } },
      },
      {},
    );
    const dir = path.join(root, 'node_modules', 'esm-only');
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(
      path.join(dir, 'package.json'),
      JSON.stringify({
        name: 'esm-only',
        version: '3.0.0',
        type: 'module',
        exports: { '.': { import: './index.js' } },
      }),
    );
    await fs.writeFile(path.join(dir, 'index.js'), 'export const a = 1;');
    // CommonJS resolution sees neither `./package.json` nor the entry point,
    // but Vite bundles it fine, so it must not be reported missing.
    await expect(
      checkInstalledNpmDependencies(root, ['esm-only']),
    ).resolves.toEqual({ missing: [], mismatched: [] });
  });

  it('is silent for a project that never pulled any', async () => {
    const root = await project({ dependencies: { react: '^19' } }, {});
    await expect(
      checkInstalledNpmDependencies(root, ['react']),
    ).resolves.toEqual({ missing: [], mismatched: [] });
  });
});
