import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { BRAND_KIT_CONFIG_FILENAME } from './brand-kit-colors';
import {
  brandKitFontResolveOptions,
  buildBrandKitCss,
  buildBrandKitFontCss,
  fontFileDataUrl,
  normalizeBrandKitFonts,
  readBrandKitFonts,
  resolveBrandKitFontFaces,
  selectResolvedFontFaces,
} from './brand-kit-fonts';

import type { FontFaceData } from 'unifont';

async function makeTempDir(): Promise<string> {
  return fs.mkdtemp(path.join(os.tmpdir(), 'canvas-brand-kit-fonts-'));
}

const tempDirs: string[] = [];

afterEach(async () => {
  await Promise.all(
    tempDirs.map((dir) => fs.rm(dir, { recursive: true, force: true })),
  );
  tempDirs.length = 0;
});

describe('normalizeBrandKitFonts', () => {
  it('keeps defaults and providers and skips unnamed families', () => {
    expect(
      normalizeBrandKitFonts({
        defaults: { weights: ['400', '700'] },
        families: [
          { name: 'Inter', provider: 'google' },
          { name: '  ' },
          null,
          { src: 'fonts/x.woff2' },
        ],
        providers: { adobe: { id: ['abc'] } },
      }),
    ).toEqual({
      defaults: { weights: ['400', '700'] },
      families: [{ name: 'Inter', provider: 'google' }],
      providers: { adobe: { id: ['abc'] } },
    });
  });

  it('returns null for a non-object value or a missing families array', () => {
    expect(normalizeBrandKitFonts(undefined)).toBeNull();
    expect(normalizeBrandKitFonts([])).toBeNull();
    expect(normalizeBrandKitFonts({ families: 'nope' })).toBeNull();
  });
});

describe('brandKitFontResolveOptions', () => {
  it('prefers the family weights and styles over the defaults', () => {
    expect(
      brandKitFontResolveOptions(
        { weights: ['300'], styles: ['italic'] },
        { name: 'Inter', weights: ['400', '100 900'], subsets: ['cyrillic'] },
      ),
    ).toEqual({
      weights: ['400', '100 900'],
      styles: ['italic'],
      subsets: ['cyrillic'],
      formats: ['woff2'],
    });
  });

  it('falls back to 400 normal and the latin subset', () => {
    expect(brandKitFontResolveOptions(undefined, { name: 'Inter' })).toEqual({
      weights: ['400'],
      styles: ['normal'],
      subsets: ['latin'],
      formats: ['woff2'],
    });
  });
});

describe('selectResolvedFontFaces', () => {
  const face = (
    weight: FontFaceData['weight'],
    style: string,
    url: string,
  ): FontFaceData => ({
    src: [{ url, format: 'woff2' }],
    weight,
    style,
  });

  it('keeps one face per requested variant and matches variable ranges', () => {
    const entry = { name: 'Inter', weights: ['400', '100 900'] };
    const selected = selectResolvedFontFaces(
      entry,
      brandKitFontResolveOptions(undefined, entry),
      [
        face(400, 'normal', 'latin.woff2'),
        face(400, 'normal', 'cyrillic.woff2'),
        face(700, 'normal', 'not-requested.woff2'),
        face([100, 900], 'normal', 'variable.woff2'),
      ],
    );
    expect(selected.map((f) => f.src[0])).toEqual([
      { url: 'latin.woff2', format: 'woff2' },
      { url: 'variable.woff2', format: 'woff2' },
    ]);
  });
});

describe('resolveBrandKitFontFaces', () => {
  it('resolves local src files against the project root', async () => {
    const faces = await resolveBrandKitFontFaces(
      {
        families: [
          {
            name: 'Brand Sans',
            src: 'fonts/brand-sans-100-900-normal.woff2',
            weights: ['100 900'],
          },
          { name: 'Brand Serif', src: 'fonts/serif.TTF', styles: ['italic'] },
        ],
      },
      '/project',
      { resolver: { resolveFont: vi.fn() } },
    );
    expect(faces).toEqual([
      {
        family: 'Brand Sans',
        weight: '100 900',
        style: 'normal',
        format: 'woff2',
        path: path.resolve('/project', 'fonts/brand-sans-100-900-normal.woff2'),
      },
      {
        family: 'Brand Serif',
        weight: '400',
        style: 'italic',
        format: 'ttf',
        path: path.resolve('/project', 'fonts/serif.TTF'),
      },
    ]);
  });

  it('resolves provider families through their provider with the defaults', async () => {
    const resolveFont = vi.fn().mockResolvedValue({
      fonts: [
        {
          src: [
            { name: 'Inter' },
            { url: 'https://fonts.example/inter-700.woff2', format: 'woff2' },
          ],
          weight: 700,
          style: 'normal',
        },
      ],
    });
    const faces = await resolveBrandKitFontFaces(
      {
        defaults: { weights: ['700'] },
        families: [{ name: 'Inter', provider: 'google' }, { name: 'Lora' }],
      },
      '/project',
      { resolver: { resolveFont } },
    );
    expect(resolveFont).toHaveBeenNthCalledWith(
      1,
      'Inter',
      expect.objectContaining({ weights: ['700'] }),
      ['google'],
    );
    expect(resolveFont).toHaveBeenNthCalledWith(
      2,
      'Lora',
      expect.anything(),
      undefined,
    );
    expect(faces[0]).toEqual({
      family: 'Inter',
      weight: '700',
      style: 'normal',
      format: 'woff2',
      url: 'https://fonts.example/inter-700.woff2',
    });
  });

  it('skips and reports families that fail to resolve', async () => {
    const onError = vi.fn();
    const faces = await resolveBrandKitFontFaces(
      { families: [{ name: 'Offline' }] },
      '/project',
      {
        resolver: { resolveFont: vi.fn().mockRejectedValue(new Error('x')) },
        onError,
      },
    );
    expect(faces).toEqual([]);
    expect(onError).toHaveBeenCalledWith('Offline', expect.any(Error));
  });
});

describe('buildBrandKitFontCss', () => {
  it('emits @font-face rules in the site brand kit CSS shape', async () => {
    const css = await buildBrandKitFontCss(
      [
        {
          family: "Brand's Sans",
          weight: '100 900',
          style: 'normal',
          format: 'ttf',
          path: '/project/fonts/brand.ttf',
        },
        {
          family: 'Inter',
          weight: '700',
          style: 'italic',
          format: 'woff2',
          url: 'https://fonts.example/inter.woff2',
        },
      ],
      (face) => `/@fs${face.path}`,
    );
    expect(css).toBe(
      [
        '@font-face {',
        "  font-family: 'Brand\\'s Sans';",
        "  src: url('/@fs/project/fonts/brand.ttf') format('truetype');",
        '  font-weight: 100 900;',
        '  font-style: normal;',
        '  font-display: swap;',
        '}',
        '',
        '@font-face {',
        "  font-family: 'Inter';",
        "  src: url('https://fonts.example/inter.woff2') format('woff2');",
        '  font-weight: 700;',
        '  font-style: italic;',
        '  font-display: swap;',
        '}',
      ].join('\n'),
    );
  });

  it('skips local faces whose URL cannot be built', async () => {
    const css = await buildBrandKitFontCss(
      [
        {
          family: 'Missing',
          weight: '400',
          style: 'normal',
          format: 'woff2',
          path: '/project/fonts/missing.woff2',
        },
      ],
      () => Promise.reject(new Error('ENOENT')),
    );
    expect(css).toBe('');
  });

  it('returns an empty string when nothing renders', async () => {
    expect(await buildBrandKitFontCss([], () => '')).toBe('');
  });
});

describe('fontFileDataUrl', () => {
  it('reads a local font file into a data URL', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    const filePath = path.join(root, 'brand.woff');
    await fs.writeFile(filePath, 'font-bytes');
    expect(
      await fontFileDataUrl({
        family: 'Brand',
        weight: '400',
        style: 'normal',
        format: 'woff',
        path: filePath,
      }),
    ).toBe(
      `data:font/woff;base64,${Buffer.from('font-bytes').toString('base64')}`,
    );
  });
});

describe('buildBrandKitCss', () => {
  it('emits fonts before colors, with local URLs relative to a generated stylesheet', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, BRAND_KIT_CONFIG_FILENAME),
      JSON.stringify({
        fonts: { families: [{ name: 'Brand', src: 'fonts/brand.woff2' }] },
        colors: { 'brand-red': '#cc0000' },
      }),
      'utf-8',
    );
    // A framework adapter writing the stylesheet to .canvas/brand-kit.css,
    // so the app's own bundler resolves the font file.
    const cssDirectory = path.join(root, '.canvas');
    const css = await buildBrandKitCss(root, {
      localFontUrl: (face) =>
        path.relative(cssDirectory, face.path).split(path.sep).join('/'),
    });
    expect(css).toBe(
      [
        '@font-face {',
        "  font-family: 'Brand';",
        "  src: url('../fonts/brand.woff2') format('woff2');",
        '  font-weight: 400;',
        '  font-style: normal;',
        '  font-display: swap;',
        '}',
        '',
        ':root {\n  --brand-red: #cc0000;\n}',
      ].join('\n'),
    );
  });

  it('returns an empty string without a brand kit file', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    expect(await buildBrandKitCss(root, { localFontUrl: () => '' })).toBe('');
  });
});

describe('readBrandKitFonts', () => {
  it('reads the fonts key from canvas.brand-kit.json', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    await fs.writeFile(
      path.join(root, BRAND_KIT_CONFIG_FILENAME),
      JSON.stringify({
        fonts: { families: [{ name: 'Inter', src: 'fonts/inter.woff2' }] },
        colors: { 'brand-red': '#cc0000' },
      }),
      'utf-8',
    );
    expect(readBrandKitFonts(root)).toEqual({
      families: [{ name: 'Inter', src: 'fonts/inter.woff2' }],
    });
  });

  it('returns null for a missing file, invalid JSON, or a non-object fonts key', async () => {
    const root = await makeTempDir();
    tempDirs.push(root);
    expect(readBrandKitFonts(root)).toBeNull();
    const file = path.join(root, BRAND_KIT_CONFIG_FILENAME);
    await fs.writeFile(file, '{ "fonts": {', 'utf-8');
    expect(readBrandKitFonts(root)).toBeNull();
    await fs.writeFile(file, JSON.stringify({ fonts: [1, 2] }), 'utf-8');
    expect(readBrandKitFonts(root)).toBeNull();
  });
});
