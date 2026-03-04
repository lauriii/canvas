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
    },
  };
});

describe('bundleVendorDependencies', () => {
  beforeEach(() => {
    // Re-apply default implementations after mockReset clears them
    vi.mocked(viteBuild).mockResolvedValue(undefined as any);
    vi.mocked(fsMock.mkdir).mockResolvedValue(undefined);
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

  it('maps CSS-only vendor packages (e.g. @fontsource/inter) from assets/ dir', async () => {
    // Mock the Vite manifest.json - CSS entries don't have a name field
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        'node_modules/@fontsource/inter/index.css': {
          file: 'assets/@fontsource--inter-abc123.css',
          src: 'node_modules/@fontsource/inter/index.css',
          isEntry: true,
        },
      }),
    );

    const result = await bundleVendorDependencies(
      new Set(['@fontsource/inter']),
      '/project',
      'src',
      'build',
    );

    expect(viteBuild).toHaveBeenCalledTimes(1);
    expect(result.importMap.imports['@fontsource/inter']).toMatch(
      /@fontsource--inter-abc123\.css$/,
    );
  });

  it('maps both JS and CSS subpath imports from same package (e.g. swiper + swiper/css)', async () => {
    // This tests the case where entry names overlap: "swiper" and "swiper--css"
    // The CSS filename "swiper--css-HASH.css" starts with "swiper-" so without
    // proper handling it could incorrectly match "swiper" instead of "swiper--css"
    vi.mocked(fsMock.readFile).mockResolvedValueOnce(
      JSON.stringify({
        'node_modules/swiper/swiper.mjs': {
          file: 'swiper-abc123.js',
          name: 'swiper',
          src: 'node_modules/swiper/swiper.mjs',
          isEntry: true,
        },
        'node_modules/swiper/swiper.css': {
          file: 'assets/swiper--css-xyz789.css',
          src: 'node_modules/swiper/swiper.css',
          isEntry: true,
        },
      }),
    );

    const result = await bundleVendorDependencies(
      new Set(['swiper', 'swiper/css']),
      '/project',
      'src',
      'build',
    );

    expect(result.success).toBe(true);
    // JS entry should map correctly
    expect(result.importMap.imports['swiper']).toMatch(/swiper-abc123\.js$/);
    // CSS subpath should map to its own file, not get confused with the JS entry
    expect(result.importMap.imports['swiper/css']).toMatch(
      /swiper--css-xyz789\.css$/,
    );
  });
});
