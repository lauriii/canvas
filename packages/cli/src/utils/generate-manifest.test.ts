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
    });

    expect(result.success).toBe(true);
    expect(result.manifest.local).toEqual(localImportMap);
  });

  it('returns empty vendor, local, and fonts when no imports provided', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: {},
    });

    expect(result.success).toBe(true);
    expect(result.manifest.vendor).toEqual({});
    expect(result.manifest.local).toEqual({});
    expect(result.manifest.fonts).toEqual({});
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

  it('writes canvas-manifest.json to outputDir', async () => {
    await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: { lodash: './vendor/lodash-abc123.js' },
      },
      localImportMap: { '@/utils': './utils.js' },
    });

    const manifestPath = path.join(tmpDir, 'canvas-manifest.json');
    const content = await fs.readFile(manifestPath, 'utf-8');
    const parsed = JSON.parse(content);

    expect(parsed.vendor).toEqual({ lodash: './vendor/lodash-abc123.js' });
    expect(parsed.local).toEqual({ '@/utils': './utils.js' });
    expect(parsed.fonts).toEqual({});
  });

  it('extracts @fontsource/* vendor packages to fonts section', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: {
          'motion/react': './vendor/motion--react-abc123.js',
          '@fontsource/inter': './vendor/assets/fontsource--inter-abc123.css',
          '@fontsource/roboto': './vendor/assets/fontsource--roboto-abc123.css',
        },
      },
      localImportMap: {},
    });

    expect(result.success).toBe(true);
    expect(result.manifest.vendor).toEqual({
      'motion/react': './vendor/motion--react-abc123.js',
    });
    expect(result.manifest.fonts).toEqual({
      '@fontsource/inter': './vendor/assets/fontsource--inter-abc123.css',
      '@fontsource/roboto': './vendor/assets/fontsource--roboto-abc123.css',
    });
  });

  it('extracts local font files to fonts section', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: {
        '@/lib/utils': './lib/utils.js',
        '@/fonts/custom.woff2': './fonts/custom.woff2',
        '@/fonts/bold.ttf': './fonts/bold.ttf',
      },
    });

    expect(result.success).toBe(true);
    expect(result.manifest.local).toEqual({
      '@/lib/utils': './lib/utils.js',
    });
    expect(result.manifest.fonts).toEqual({
      '@/fonts/bold.ttf': './fonts/bold.ttf',
      '@/fonts/custom.woff2': './fonts/custom.woff2',
    });
  });

  it('merges vendor and local fonts into single fonts section', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: {
          '@fontsource/inter': './vendor/assets/fontsource--inter-abc123.css',
        },
      },
      localImportMap: {
        '@/fonts/custom.woff2': './fonts/custom.woff2',
      },
    });

    expect(result.success).toBe(true);
    expect(result.manifest.fonts).toEqual({
      '@/fonts/custom.woff2': './fonts/custom.woff2',
      '@fontsource/inter': './vendor/assets/fontsource--inter-abc123.css',
    });
  });

  it('sorts fonts alphabetically', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: {
        imports: {
          '@fontsource/roboto': './vendor/assets/fontsource--roboto-abc123.css',
          '@fontsource/inter': './vendor/assets/fontsource--inter-abc123.css',
        },
      },
      localImportMap: {
        '@/fonts/z-font.woff2': './fonts/z-font.woff2',
        '@/fonts/a-font.woff2': './fonts/a-font.woff2',
      },
    });

    expect(result.success).toBe(true);
    expect(Object.keys(result.manifest.fonts)).toEqual([
      '@/fonts/a-font.woff2',
      '@/fonts/z-font.woff2',
      '@fontsource/inter',
      '@fontsource/roboto',
    ]);
  });

  it('recognizes all font file extensions', async () => {
    const result = await generateManifest({
      outputDir: tmpDir,
      vendorImportMap: null,
      localImportMap: {
        '@/fonts/font.woff': './fonts/font.woff',
        '@/fonts/font.woff2': './fonts/font.woff2',
        '@/fonts/font.ttf': './fonts/font.ttf',
        '@/fonts/font.otf': './fonts/font.otf',
        '@/fonts/font.eot': './fonts/font.eot',
        '@/lib/utils': './lib/utils.js',
      },
    });

    expect(result.success).toBe(true);
    expect(result.manifest.local).toEqual({
      '@/lib/utils': './lib/utils.js',
    });
    expect(result.manifest.fonts).toEqual({
      '@/fonts/font.eot': './fonts/font.eot',
      '@/fonts/font.otf': './fonts/font.otf',
      '@/fonts/font.ttf': './fonts/font.ttf',
      '@/fonts/font.woff': './fonts/font.woff',
      '@/fonts/font.woff2': './fonts/font.woff2',
    });
  });
});
