import { promises as fsMock } from 'node:fs';
import { build as viteBuild } from 'vite';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { bundleLocalAliasImports } from './build-local-import';

import type * as NodeFs from 'node:fs';

// Mock vite before importing build-local-import
vi.mock('vite', () => ({ build: vi.fn() }));

// Mock node:fs partially
vi.mock('node:fs', async (importOriginal) => {
  const actual = await importOriginal<typeof NodeFs>();
  return {
    ...actual,
    promises: {
      ...actual.promises,
      mkdir: vi.fn(),
      copyFile: vi.fn(),
      readFile: vi.fn(),
    },
  };
});

describe('bundleLocalAliasImports', () => {
  beforeEach(() => {
    // Re-apply default implementations after mockReset clears them
    vi.mocked(viteBuild).mockResolvedValue(undefined as any);
    vi.mocked(fsMock.mkdir).mockResolvedValue(undefined);
    vi.mocked(fsMock.copyFile).mockResolvedValue(undefined);
    vi.mocked(fsMock.readFile).mockResolvedValue('{}');
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns success with empty localImportMap when aliasImports is empty', async () => {
    const result = await bundleLocalAliasImports(
      new Map(),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.success).toBe(true);
    expect(result.localImportMap).toEqual({});
  });

  it('maps JS module alias import to .js output path', async () => {
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        '/project/src/lib/utils.ts': {
          file: 'utils-abc123.js',
          src: '/project/src/lib/utils.ts',
          isEntry: true,
          name: 'utils',
        },
      }),
    );
    const result = await bundleLocalAliasImports(
      new Map([['@/lib/utils', '/project/src/lib/utils.ts']]),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.localImportMap['@/lib/utils']).toMatch('utils-abc123.js');
  });

  it('maps JS alias import from component sub-dir to flat output path', async () => {
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        '/project/src/component/pricing/helpers.ts': {
          file: 'helpers-abc123.js',
          src: '/project/src/component/pricing/helpers.ts',
          isEntry: true,
          name: 'helpers',
        },
      }),
    );
    const result = await bundleLocalAliasImports(
      new Map([
        [
          '@/component/pricing/helpers',
          '/project/src/component/pricing/helpers.ts',
        ],
      ]),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.localImportMap['@/component/pricing/helpers']).toMatch(
      /helpers.*\.js$/,
    );
  });

  it('preserves imports with same basename in different directories', async () => {
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        '/project/src/lib/a/helpers.ts': {
          file: 'helpers-a1.js',
          src: '/project/src/lib/a/helpers.ts',
          isEntry: true,
          name: 'helpers',
        },
        '/project/src/lib/b/helpers.ts': {
          file: 'helpers-b2.js',
          src: '/project/src/lib/b/helpers.ts',
          isEntry: true,
          name: 'helpers--1',
        },
      }),
    );

    const result = await bundleLocalAliasImports(
      new Map([
        ['@/lib/a/helpers', '/project/src/lib/a/helpers.ts'],
        ['@/lib/b/helpers', '/project/src/lib/b/helpers.ts'],
      ]),
      '/project',
      'src',
      '/project/build',
    );

    const viteConfig = vi.mocked(viteBuild).mock.calls[0]?.[0];
    const input =
      viteConfig &&
      typeof viteConfig === 'object' &&
      'build' in viteConfig &&
      viteConfig.build &&
      typeof viteConfig.build === 'object' &&
      'rollupOptions' in viteConfig.build &&
      viteConfig.build.rollupOptions &&
      typeof viteConfig.build.rollupOptions === 'object' &&
      'input' in viteConfig.build.rollupOptions
        ? (viteConfig.build.rollupOptions.input as Record<string, string>)
        : {};

    expect(Object.keys(input)).toHaveLength(2);
    expect(Object.values(input)).toEqual(
      expect.arrayContaining([
        '/project/src/lib/a/helpers.ts',
        '/project/src/lib/b/helpers.ts',
      ]),
    );
    expect(result.localImportMap).toEqual({
      '@/lib/a/helpers': './local/helpers-a1.js',
      '@/lib/b/helpers': './local/helpers-b2.js',
    });
  });

  it('bubbles Vite build errors to caller', async () => {
    vi.mocked(viteBuild).mockRejectedValueOnce(new Error('Vite exploded'));

    await expect(
      bundleLocalAliasImports(
        new Map([['@/lib/utils', '/project/src/lib/utils.ts']]),
        '/project',
        'src',
        '/project/build',
      ),
    ).rejects.toThrow('Vite exploded');
  });
});
