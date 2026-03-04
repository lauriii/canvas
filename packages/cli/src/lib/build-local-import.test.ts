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
    },
  };
});

describe('bundleLocalAliasImports', () => {
  beforeEach(() => {
    // Re-apply default implementations after mockReset clears them
    vi.mocked(viteBuild).mockResolvedValue(undefined as any);
    vi.mocked(fsMock.mkdir).mockResolvedValue(undefined);
    vi.mocked(fsMock.copyFile).mockResolvedValue(undefined);
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
    const result = await bundleLocalAliasImports(
      new Map([['@/lib/utils', '/project/src/lib/utils.ts']]),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.localImportMap['@/lib/utils']).toMatch(/lib\/utils\.js$/);
  });

  it('maps JS alias import from component sub-dir to correct path', async () => {
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
      /component\/pricing\/helpers\.js$/,
    );
  });

  it('maps CSS side-effect alias import to .css output path', async () => {
    const result = await bundleLocalAliasImports(
      new Map([
        [
          '@/utils/styles/carousel.css',
          '/project/src/utils/styles/carousel.css',
        ],
      ]),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.localImportMap['@/utils/styles/carousel.css']).toMatch(
      /utils\/styles\/carousel\.css$/,
    );
  });

  it('maps image asset alias import preserving original extension', async () => {
    const result = await bundleLocalAliasImports(
      new Map([
        ['@/components/hero/hero.jpg', '/project/src/components/hero/hero.jpg'],
      ]),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.localImportMap['@/components/hero/hero.jpg']).toMatch(
      /components\/hero\/hero\.jpg$/,
    );
  });

  it('copies image asset (does not call viteBuild)', async () => {
    await bundleLocalAliasImports(
      new Map([
        ['@/components/hero/hero.jpg', '/project/src/components/hero/hero.jpg'],
      ]),
      '/project',
      'src',
      '/project/build',
    );

    expect(viteBuild).not.toHaveBeenCalled();
    expect(fsMock.copyFile).toHaveBeenCalledWith(
      '/project/src/components/hero/hero.jpg',
      expect.stringContaining('hero.jpg'),
    );
  });

  it('maps SVG alias import preserving .svg extension', async () => {
    const result = await bundleLocalAliasImports(
      new Map([
        ['@/components/cart/cart.svg', '/project/src/components/cart/cart.svg'],
      ]),
      '/project',
      'src',
      '/project/build',
    );
    expect(result.localImportMap['@/components/cart/cart.svg']).toMatch(
      /components\/cart\/cart\.svg$/,
    );
  });

  it('copies SVG file (does not call viteBuild)', async () => {
    await bundleLocalAliasImports(
      new Map([
        ['@/components/cart/cart.svg', '/project/src/components/cart/cart.svg'],
      ]),
      '/project',
      'src',
      '/project/build',
    );

    expect(viteBuild).not.toHaveBeenCalled();
    expect(fsMock.copyFile).toHaveBeenCalledWith(
      '/project/src/components/cart/cart.svg',
      expect.stringContaining('cart.svg'),
    );
  });

  it('preserves directory path to disambiguate same-named files from different directories', async () => {
    const result = await bundleLocalAliasImports(
      new Map([
        ['@/lib/utils', '/project/src/lib/utils.ts'],
        ['@/components/utils', '/project/src/components/utils.ts'],
      ]),
      '/project',
      'src',
      '/project/build',
    );
    // Both should be present with distinct paths
    expect(result.localImportMap['@/lib/utils']).toMatch(/lib\/utils\.js$/);
    expect(result.localImportMap['@/components/utils']).toMatch(
      /components\/utils\.js$/,
    );
    expect(result.localImportMap['@/lib/utils']).not.toBe(
      result.localImportMap['@/components/utils'],
    );
  });
});
