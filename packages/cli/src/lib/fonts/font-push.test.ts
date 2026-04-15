import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { downloadResolvedFaces } from './font-downloader';
import { extractVariableFontAxes } from './font-metadata';
import { buildFontPushPlannedResults, pushFonts } from './font-push';
import { createFontResolver } from './font-resolver';

import type { Config } from '../../config';
import type { ApiService } from '../../services/api';
import type { BrandKitFontEntry } from '../../types/Component';

vi.mock('./font-resolver.js', () => ({
  createFontResolver: vi.fn().mockResolvedValue({
    resolveFont: vi.fn().mockResolvedValue(undefined),
  }),
}));

vi.mock('./font-downloader.js', () => ({
  downloadResolvedFaces: vi.fn().mockResolvedValue([]),
}));

vi.mock('./font-metadata.js', () => ({
  extractVariableFontAxes: vi.fn().mockResolvedValue(null),
}));

describe('pushFonts', () => {
  let tmpDir: string;
  let originalCwd: string;
  let config: Config;
  let api: ApiService;

  beforeEach(async () => {
    originalCwd = process.cwd();
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'font-push-test-'));
    process.chdir(tmpDir);
    config = {
      siteUrl: 'https://example.com',
      clientId: 'id',
      clientSecret: 'secret',
      scope: 'canvas:js_component canvas:asset_library canvas:brand_kit',
      userAgent: '',
      includePages: false,
      includeBrandKit: true,
      aliasBaseDir: 'src',
      outputDir: 'dist',
      componentDir: tmpDir,
      pagesDir: './pages',
      deprecatedComponentDir: tmpDir,
      globalCssPath: './src/global.css',
    };
    api = {
      getBrandKit: vi.fn().mockResolvedValue({ id: 'global', fonts: [] }),
      uploadFont: vi
        .fn()
        .mockResolvedValue({ uri: 'public://canvas/font.woff2', fid: 1 }),
      updateBrandKit: vi.fn().mockResolvedValue({}),
    } as unknown as ApiService;
  });

  afterEach(async () => {
    process.chdir(originalCwd);
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('returns count 0 and skipped 0 when config.fonts is undefined', async () => {
    const result = await pushFonts(config, api);
    expect(result.count).toBe(0);
    expect(result.skipped).toBe(0);
    expect(result.outcomes).toEqual([]);
    expect(api.getBrandKit).not.toHaveBeenCalled();
    expect(api.uploadFont).not.toHaveBeenCalled();
    expect(api.updateBrandKit).not.toHaveBeenCalled();
  });

  it('returns count 0 and skipped 0 when config.fonts.families is empty; push with empty families clears remote fonts', async () => {
    config.fonts = { families: [] };
    const result = await pushFonts(config, api);
    expect(result.count).toBe(0);
    expect(result.skipped).toBe(0);
    expect(result.deleted).toBe(0);
    expect(result.outcomes).toEqual([]);
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
    expect(api.updateBrandKit).toHaveBeenCalledWith({ fonts: [] });
  });

  it('reports deleted count when clearing with empty families and remote had fonts', async () => {
    config.fonts = { families: [] };
    vi.mocked(api.getBrandKit).mockResolvedValueOnce({
      id: 'global',
      fonts: [
        {
          id: 'a',
          family: 'Old',
          uri: 'public://canvas/old.woff2',
          url: 'https://example.com/old.woff2',
          format: 'woff2',
          weight: '400',
          style: 'normal',
        },
        {
          id: 'b',
          family: 'Other',
          uri: 'public://canvas/other.woff2',
          url: 'https://example.com/other.woff2',
          format: 'woff2',
          weight: '700',
          style: 'normal',
        },
      ],
    });
    const result = await pushFonts(config, api);
    expect(result.deleted).toBe(2);
    expect(result.outcomes).toEqual([
      expect.objectContaining({ operation: 'delete' }),
      expect.objectContaining({ operation: 'delete' }),
    ]);
    expect(api.updateBrandKit).toHaveBeenCalledWith({ fonts: [] });
  });

  it('uploads local src font and calls updateBrandKit with one entry', async () => {
    const fontPath = path.join(tmpDir, 'MyFont.woff2');
    await fs.writeFile(fontPath, Buffer.from([0x00, 0x01]), 'utf-8');
    config.fonts = {
      families: [
        {
          name: 'My Font',
          src: 'MyFont.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
      ],
    };

    const result = await pushFonts(config, api);

    expect(result.count).toBe(1);
    expect(result.skipped).toBe(0);
    expect(result.outcomes).toEqual([
      expect.objectContaining({
        itemName: 'My Font 400 normal',
        operation: 'create',
      }),
    ]);
    expect(api.uploadFont).toHaveBeenCalledTimes(1);
    expect(api.uploadFont).toHaveBeenCalledWith(
      expect.stringContaining('MyFont.woff2'),
      'my-font-400-normal.woff2',
    );
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
    expect(api.updateBrandKit).toHaveBeenCalledWith({
      fonts: [
        expect.objectContaining({
          family: 'My Font',
          uri: 'public://canvas/font.woff2',
          format: 'woff2',
          weight: '400',
          style: 'normal',
        }),
      ],
    });
  });

  it('throws validation error when local src file is missing', async () => {
    config.fonts = {
      families: [
        { name: 'Missing', src: 'nonexistent.woff2', weights: ['400'] },
      ],
    };

    const err = await pushFonts(config, api).catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    expect((err as Error).message).toContain('Font config validation failed');
    expect((err as Error).message).toContain(
      'file not found: nonexistent.woff2',
    );
    expect(api.uploadFont).not.toHaveBeenCalled();
  });

  it('throws validation error when family has missing or empty name', async () => {
    config.fonts = {
      families: [{ name: '' }, { name: '  ' }],
    };

    const err = await pushFonts(config, api).catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    expect((err as Error).message).toContain('Font config validation failed');
    expect((err as Error).message).toContain('missing or empty "name"');
    expect(api.getBrandKit).not.toHaveBeenCalled();
  });

  it('throws validation error when provider is invalid', async () => {
    config.fonts = {
      families: [{ name: 'Inter', provider: 'invalid' as 'google' }],
    };

    const err = await pushFonts(config, api).catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    expect((err as Error).message).toContain('Font config validation failed');
    expect((err as Error).message).toContain('invalid "provider"');
    expect((err as Error).message).toContain('Expected one of:');
    expect(api.uploadFont).not.toHaveBeenCalled();
  });

  it('throws with all validation errors at once', async () => {
    const fontPath = path.join(tmpDir, 'Good.woff2');
    await fs.writeFile(fontPath, Buffer.from([0x00]), 'utf-8');
    config.fonts = {
      families: [
        { name: '' },
        { name: 'MissingFile', src: 'nonexistent.woff2' },
        { name: 'BadProvider', provider: 'unknown' as 'google' },
      ],
    };

    const err = await pushFonts(config, api).catch((e) => e);
    expect(err).toBeInstanceOf(Error);
    const msg = (err as Error).message;
    expect(msg).toContain('Font config validation failed');
    expect(msg).toContain('missing or empty "name"');
    expect(msg).toContain('file not found: nonexistent.woff2');
    expect(msg).toContain('invalid "provider"');
    expect(api.uploadFont).not.toHaveBeenCalled();
  });

  it('skips upload and PATCH when all variants already exist on backend', async () => {
    const fontPath = path.join(tmpDir, 'MyFont.woff2');
    await fs.writeFile(fontPath, Buffer.from([0x00, 0x01]), 'utf-8');
    config.fonts = {
      families: [
        {
          name: 'My Font',
          src: 'MyFont.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
      ],
    };
    const existingEntry = {
      id: 'existing-uuid',
      family: 'My Font',
      uri: 'public://canvas/assets/MyFont.woff2',
      url: 'https://example.com/sites/default/files/canvas/assets/MyFont.woff2',
      format: 'woff2',
      weight: '400',
      style: 'normal',
    };
    vi.mocked(api.getBrandKit).mockResolvedValueOnce({
      id: 'global',
      fonts: [existingEntry],
    });

    const result = await pushFonts(config, api);

    expect(result.count).toBe(0);
    expect(result.skipped).toBe(1);
    expect(api.uploadFont).not.toHaveBeenCalled();
    expect(api.updateBrandKit).not.toHaveBeenCalled();
  });

  it('uploads only new variants when some already exist on backend', async () => {
    const fontPath400 = path.join(tmpDir, 'MyFont-400.woff2');
    const fontPath700 = path.join(tmpDir, 'MyFont-700.woff2');
    await fs.writeFile(fontPath400, Buffer.from([0x00]), 'utf-8');
    await fs.writeFile(fontPath700, Buffer.from([0x01]), 'utf-8');
    config.fonts = {
      families: [
        {
          name: 'My Font',
          src: 'MyFont-400.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
        {
          name: 'My Font',
          src: 'MyFont-700.woff2',
          weights: ['700'],
          styles: ['normal'],
        },
      ],
    };
    const existing400 = {
      id: 'existing-400',
      family: 'My Font',
      uri: 'public://canvas/assets/MyFont-400.woff2',
      url: 'https://example.com/sites/default/files/canvas/assets/MyFont-400.woff2',
      format: 'woff2',
      weight: '400',
      style: 'normal',
    };
    vi.mocked(api.getBrandKit).mockResolvedValueOnce({
      id: 'global',
      fonts: [existing400],
    });

    const result = await pushFonts(config, api);

    expect(result.count).toBe(1);
    expect(result.skipped).toBe(1);
    expect(api.uploadFont).toHaveBeenCalledTimes(1);
    expect(api.uploadFont).toHaveBeenCalledWith(
      expect.stringContaining('MyFont-700.woff2'),
      'my-font-700-normal.woff2',
    );
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
    const call = vi.mocked(api.updateBrandKit).mock.calls[0];
    const fonts = (call[0] as unknown as { fonts: BrandKitFontEntry[] }).fonts;
    expect(fonts).toHaveLength(2);
    expect(fonts[0]).toMatchObject({
      id: 'existing-400',
      family: 'My Font',
      uri: 'public://canvas/assets/MyFont-400.woff2',
      format: 'woff2',
      weight: '400',
      style: 'normal',
    });
    expect(fonts[0]).not.toHaveProperty('url');
    expect(fonts[0]).not.toHaveProperty('variantType');
    expect(fonts[1]).toMatchObject({
      family: 'My Font',
      weight: '700',
      style: 'normal',
    });
  });

  it('reports deleted count when pushing fewer variants than on remote', async () => {
    const fontPath = path.join(tmpDir, 'Keep.woff2');
    await fs.writeFile(fontPath, Buffer.from([0x00]), 'utf-8');
    config.fonts = {
      families: [
        {
          name: 'Keep',
          src: 'Keep.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
      ],
    };
    vi.mocked(api.getBrandKit).mockResolvedValueOnce({
      id: 'global',
      fonts: [
        {
          id: 'keep-id',
          family: 'Keep',
          uri: 'public://canvas/keep.woff2',
          url: 'https://example.com/keep.woff2',
          format: 'woff2',
          weight: '400',
          style: 'normal',
        },
        {
          id: 'remove-id',
          family: 'Removed',
          uri: 'public://canvas/removed.woff2',
          url: 'https://example.com/removed.woff2',
          format: 'woff2',
          weight: '400',
          style: 'normal',
        },
      ],
    });
    const result = await pushFonts(config, api);
    expect(result.deleted).toBe(1);
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
    expect(
      (
        vi.mocked(api.updateBrandKit).mock.calls[0][0] as unknown as {
          fonts: unknown[];
        }
      ).fonts,
    ).toHaveLength(1);
  });

  it('falls back to uploading all when getBrandKit fails', async () => {
    const fontPath = path.join(tmpDir, 'MyFont.woff2');
    await fs.writeFile(fontPath, Buffer.from([0x00, 0x01]), 'utf-8');
    config.fonts = {
      families: [
        {
          name: 'My Font',
          src: 'MyFont.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
      ],
    };
    vi.mocked(api.getBrandKit).mockRejectedValueOnce(
      new Error('Network error'),
    );

    const result = await pushFonts(config, api);

    expect(result.count).toBe(1);
    expect(result.skipped).toBe(0);
    expect(api.uploadFont).toHaveBeenCalledTimes(1);
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
  });

  it('treats provider-resolved individual weights as static (no axes) even when file is variable', async () => {
    const face400 = {
      weight: 400,
      style: 'normal',
      src: [{ url: 'https://example.com/f.woff2', format: 'woff2' }],
    };
    const face600 = {
      weight: 600,
      style: 'normal',
      src: [{ url: 'https://example.com/f.woff2', format: 'woff2' }],
    };
    vi.mocked(createFontResolver).mockResolvedValueOnce({
      resolveFont: vi.fn().mockResolvedValueOnce({
        fonts: [face400, face600],
      }),
      listFonts: vi.fn().mockResolvedValue([]),
    } as Awaited<ReturnType<typeof createFontResolver>>);

    const tempPath1 = path.join(tmpDir, 'p1.woff2');
    const tempPath2 = path.join(tmpDir, 'p2.woff2');
    await fs.writeFile(tempPath1, Buffer.from([0]));
    await fs.writeFile(tempPath2, Buffer.from([0]));
    vi.mocked(downloadResolvedFaces).mockResolvedValueOnce([
      { face: face400, weight: '400', style: 'normal', tempPath: tempPath1 },
      { face: face600, weight: '600', style: 'normal', tempPath: tempPath2 },
    ]);

    vi.mocked(extractVariableFontAxes).mockResolvedValueOnce([
      { tag: 'wght', min: 100, max: 900, default: 400 },
    ]);

    config.fonts = {
      families: [{ name: 'Noto Sans TC', weights: ['400', '600'] }],
    };
    const result = await pushFonts(config, api);

    expect(result.count).toBe(2);
    expect(api.uploadFont).toHaveBeenCalledTimes(2);
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
    const fonts = (
      vi.mocked(api.updateBrandKit).mock.calls[0][0] as unknown as {
        fonts: BrandKitFontEntry[];
      }
    ).fonts;
    expect(fonts).toHaveLength(2);
    expect(fonts[0]).not.toHaveProperty('axes');
    expect(fonts[1]).not.toHaveProperty('axes');
    expect(extractVariableFontAxes).not.toHaveBeenCalled();
  });

  it('matches variable font face when config uses weight range (e.g. npm @fontsource-variable)', async () => {
    const variableFace = {
      weight: [100, 900] as [number, number],
      style: 'normal',
      src: [
        { url: 'https://cdn.example.com/montserrat.woff2', format: 'woff2' },
      ],
    };
    vi.mocked(createFontResolver).mockResolvedValueOnce({
      resolveFont: vi.fn().mockResolvedValueOnce({
        fonts: [variableFace],
      }),
      listFonts: vi.fn().mockResolvedValue([]),
    } as Awaited<ReturnType<typeof createFontResolver>>);

    const tempPath = path.join(tmpDir, 'variable.woff2');
    await fs.writeFile(tempPath, Buffer.from([0]));
    vi.mocked(downloadResolvedFaces).mockResolvedValueOnce([
      {
        face: variableFace,
        weight: '100 900',
        style: 'normal',
        tempPath,
      },
    ]);
    vi.mocked(extractVariableFontAxes).mockResolvedValueOnce([
      { tag: 'wght', min: 100, max: 900, default: 400 },
    ]);

    config.fonts = {
      families: [
        {
          name: 'Montserrat Variable',
          weights: ['100 900'],
          styles: ['normal'],
        },
      ],
    };
    const result = await pushFonts(config, api);

    expect(result.count).toBe(1);
    expect(api.uploadFont).toHaveBeenCalledTimes(1);
    expect(api.updateBrandKit).toHaveBeenCalledTimes(1);
    const fonts = (
      vi.mocked(api.updateBrandKit).mock.calls[0][0] as unknown as {
        fonts: BrandKitFontEntry[];
      }
    ).fonts;
    expect(fonts).toHaveLength(1);
    expect(fonts[0]).toMatchObject({
      family: 'Montserrat Variable',
      weight: '100 900',
      style: 'normal',
    });
    expect(fonts[0].axes).toEqual([
      { tag: 'wght', name: 'Weight', min: 100, max: 900, default: 400 },
    ]);
  });

  it('applies axisDefaults override for variable font default', async () => {
    const variableFace = {
      weight: [100, 900] as [number, number],
      style: 'normal',
      src: [{ url: 'https://cdn.example.com/f.woff2', format: 'woff2' }],
    };
    vi.mocked(createFontResolver).mockResolvedValueOnce({
      resolveFont: vi.fn().mockResolvedValueOnce({ fonts: [variableFace] }),
      listFonts: vi.fn().mockResolvedValue([]),
    } as Awaited<ReturnType<typeof createFontResolver>>);
    const tempPath = path.join(tmpDir, 'v.woff2');
    await fs.writeFile(tempPath, Buffer.from([0]));
    vi.mocked(downloadResolvedFaces).mockResolvedValueOnce([
      { face: variableFace, weight: '100 900', style: 'normal', tempPath },
    ]);
    vi.mocked(extractVariableFontAxes).mockResolvedValueOnce([
      { tag: 'wght', min: 100, max: 900, default: 400 },
    ]);

    config.fonts = {
      families: [
        {
          name: 'Variable Family',
          weights: ['100 900'],
          styles: ['normal'],
          axisDefaults: { wght: 500 },
        },
      ],
    };
    await pushFonts(config, api);

    const fonts = (
      vi.mocked(api.updateBrandKit).mock.calls[0][0] as unknown as {
        fonts: BrandKitFontEntry[];
      }
    ).fonts;
    expect(fonts[0].axes).toEqual([
      { tag: 'wght', name: 'Weight', min: 100, max: 900, default: 500 },
    ]);
  });
});

describe('buildFontPushPlannedResults', () => {
  const labels = { create: 'Create', update: 'Update', delete: 'Delete' };

  it('treats remote min weight as matching local variable range (single Update, no extra Create)', () => {
    const results = buildFontPushPlannedResults(
      {
        families: [
          {
            name: 'Montserrat Variable',
            weights: ['100 900'],
            styles: ['normal'],
          },
        ],
      },
      [
        {
          id: '1',
          family: 'Montserrat Variable',
          uri: 'public://x',
          format: 'woff2',
          weight: '100',
          style: 'normal',
        },
      ],
      labels,
    );
    expect(results).toHaveLength(1);
    expect(results[0]?.details?.[0]?.content).toBe('Update');
  });
});
