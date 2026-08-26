import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { generateManifest } from './generate-manifest';

describe('generateManifest', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-manifest-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('populates vendor section from vendorImportMap (non-font packages)', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: {
          'motion/react': './vendor/motion--react-abc123.js',
          lodash: './vendor/lodash-abc123.js',
        },
      },
      localImportMap: {},
      sharedChunks: [],
    });

    expect(result.success).toBe(true);
    expect(result.manifest.vendor).toEqual({
      lodash: './vendor/lodash-abc123.js',
      'motion/react': './vendor/motion--react-abc123.js',
    });
  });

  it('populates local section from localImportMap with all alias import types', async () => {
    const localImportMap = {
      '@/lib/utils': './lib/utils.js',
      '@/component/pricing/helpers': './component/pricing/helpers.js',
      '@/components/hero/hero.jpg': './components/hero/hero.jpg',
      '@/components/cart/cart.svg': './components/cart/cart.svg',
      '@/utils/styles/carousel.css': './utils/styles/carousel.css',
    };
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap,
      sharedChunks: [],
    });

    expect(result.success).toBe(true);
    expect(result.manifest.local).toEqual(localImportMap);
  });

  it('returns empty vendor, local when no imports provided', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: {},
      sharedChunks: [],
    });

    expect(result.success).toBe(true);
    expect(result.manifest).toEqual({
      vendor: {},
      local: {},
      shared: [],
    });
  });

  it('sorts vendor and local keys alphabetically', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: {
          zod: './vendor/zod-abc123.js',
          axios: './vendor/axios-abc123.js',
          'motion/react': './vendor/motion--react-abc123.js',
        },
      },
      localImportMap: {
        '@/z-utils': './z-utils.js',
        '@/a-utils': './a-utils.js',
      },
      sharedChunks: [],
    });

    expect(result.success).toBe(true);
    expect(Object.keys(result.manifest.vendor)).toEqual([
      'axios',
      'motion/react',
      'zod',
    ]);
    expect(Object.keys(result.manifest.local)).toEqual([
      '@/a-utils',
      '@/z-utils',
    ]);
  });

  it('adds localSources with path and text source, sorted, when provided', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: {
        '@/lib/foo': './local/foo-abc.js',
        '@/assets/poster.webp': './local/poster-def.webp',
      },
      localSources: {
        '@/lib/foo': {
          path: 'src/lib/foo.ts',
          source: 'export const x = 1;\n',
        },
        '@/assets/poster.webp': { path: 'src/assets/poster.webp' },
      },
      sharedChunks: [],
    });

    expect(result.success).toBe(true);
    expect(result.manifest.localSources).toEqual({
      '@/assets/poster.webp': { path: 'src/assets/poster.webp' },
      '@/lib/foo': { path: 'src/lib/foo.ts', source: 'export const x = 1;\n' },
    });
    // Sorted alphabetically by specifier.
    expect(Object.keys(result.manifest.localSources ?? {})).toEqual([
      '@/assets/poster.webp',
      '@/lib/foo',
    ]);
  });

  it('omits localSources when none provided or empty', async () => {
    const withoutMeta = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: { '@/lib/foo': './local/foo-abc.js' },
      sharedChunks: [],
    });
    expect(withoutMeta.manifest.localSources).toBeUndefined();

    const emptyMeta = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: { '@/lib/foo': './local/foo-abc.js' },
      localSources: {},
      sharedChunks: [],
    });
    expect(emptyMeta.manifest.localSources).toBeUndefined();
  });

  it('writes canvas-manifest.json to outputDir', async () => {
    await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: { lodash: './vendor/lodash-abc123.js' },
      },
      localImportMap: { '@/utils': './utils.js' },
      sharedChunks: [],
    });

    const manifestPath = path.join(tmpDir, 'canvas-manifest.json');
    const content = await fs.readFile(manifestPath, 'utf-8');
    const parsed = JSON.parse(content);

    expect(parsed.vendor).toEqual({ lodash: './vendor/lodash-abc123.js' });
    expect(parsed.local).toEqual({ '@/utils': './utils.js' });
  });
});
