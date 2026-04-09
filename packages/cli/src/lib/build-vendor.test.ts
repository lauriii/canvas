import { promises as fsMock } from 'node:fs';
import { build as viteBuild } from 'vite';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { bundleVendorDependencies } from './build-vendor';

import type * as NodeFs from 'node:fs';

// Mock vite before importing build-vendor
vi.mock('vite', () => ({ build: vi.fn() }));

// Mock node:fs partially — only what build-vendor uses
vi.mock('node:fs', async (importOriginal) => {
  const actual = await importOriginal<typeof NodeFs>();
  return {
    ...actual,
    promises: {
      ...actual.promises,
      mkdir: vi.fn(),
      readFile: vi.fn(),
      writeFile: vi.fn(),
    },
  };
});

describe('bundleVendorDependencies', () => {
  beforeEach(() => {
    // Re-apply default implementations after mockReset clears them
    vi.mocked(viteBuild).mockResolvedValue(undefined as any);
    vi.mocked(fsMock.mkdir).mockResolvedValue(undefined);
    vi.mocked(fsMock.writeFile).mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns success with empty importMap when package set is empty', async () => {
    const result = await bundleVendorDependencies(
      new Set(),
      '/project',
      'src',
      'build',
    );
    expect(result.success).toBe(true);
    expect(result.importMap.imports).toEqual({});
    expect(result.bundledPackages).toHaveLength(0);
  });

  it('calls viteBuild for packages like motion/react', async () => {
    // Mock the Vite manifest.json
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        'node_modules/motion/react/dist/index.mjs': {
          file: 'motion--react-abc123.js',
          name: 'motion--react',
          src: 'node_modules/motion/react/dist/index.mjs',
          isEntry: true,
        },
      }),
    );

    const result = await bundleVendorDependencies(
      new Set(['motion/react']),
      '/project',
      'src',
      'build',
    );

    expect(viteBuild).toHaveBeenCalledTimes(1);
    expect(result.success).toBe(true);
    // The import map should map 'motion/react' to the generated file
    expect(result.importMap.imports['motion/react']).toMatch(
      /motion--react-abc123\.js$/,
    );
  });

  it('skips packages provided by Canvas global import map', async () => {
    const result = await bundleVendorDependencies(
      new Set([
        'preact',
        'clsx',
        'class-variance-authority',
        'tailwind-merge',
        'swr',
        'drupal-canvas',
        '@tailwindcss/typography',
        'next-image-standalone',
        '@drupal-api-client/json-api-client',
      ]),
      '/project',
      'src',
      'build',
    );

    expect(viteBuild).not.toHaveBeenCalled();
    expect(result.success).toBe(true);
    expect(result.importMap.imports).toEqual({});
    expect(result.bundledPackages).toHaveLength(0);
  });

  it('filters Canvas externals but bundles other packages', async () => {
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        'node_modules/lodash-es/lodash.js': {
          file: 'lodash-es-abc123.js',
          name: 'lodash-es',
          src: 'node_modules/lodash-es/lodash.js',
          isEntry: true,
        },
      }),
    );

    const result = await bundleVendorDependencies(
      new Set(['preact', 'clsx', 'lodash-es']),
      '/project',
      'src',
      'build',
    );

    expect(viteBuild).toHaveBeenCalledTimes(1);
    expect(result.success).toBe(true);
    // Only lodash-es should be bundled, not preact or clsx
    expect(result.importMap.imports['lodash-es']).toMatch(
      /lodash-es-abc123\.js$/,
    );
    expect(result.importMap.imports).not.toHaveProperty('preact');
    expect(result.importMap.imports).not.toHaveProperty('clsx');
  });

  it('bubbles Vite build errors to caller', async () => {
    vi.mocked(viteBuild).mockRejectedValueOnce(new Error('Vite exploded'));

    await expect(
      bundleVendorDependencies(new Set(['lodash']), '/project', 'src', 'build'),
    ).rejects.toThrow('Vite exploded');
  });
});
