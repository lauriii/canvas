import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  buildExistingVariantKeys,
  buildPrimaryVariantKeys,
  parseVariantKey,
  pullFonts,
  readBrandKitConfig,
  updateBrandKitConfig,
  variantKey,
} from './font-pull';

import type { FontFamilyEntry } from '../../config';
import type { ApiService } from '../../services/api';
import type { BrandKit } from '../../types/Component';

describe('font-pull', () => {
  describe('variantKey', () => {
    it('normalizes family, weight, and style to lowercase', () => {
      expect(variantKey('Inter', '400', 'normal')).toBe(
        variantKey('INTER', '400', 'NORMAL'),
      );
      expect(variantKey('Inter', '400', 'normal')).toContain('inter');
      expect(variantKey('Inter', '400', 'normal')).toContain('400');
      expect(variantKey('Inter', '400', 'normal')).toContain('normal');
    });

    it('defaults weight and style when empty', () => {
      expect(variantKey('Inter', '', '')).toBe(
        variantKey('inter', '400', 'normal'),
      );
    });
  });

  describe('parseVariantKey', () => {
    it('inverts variantKey output', () => {
      const key = variantKey('Inter', '400', 'normal');
      expect(parseVariantKey(key)).toEqual({
        family: 'inter',
        weight: '400',
        style: 'normal',
      });
    });
  });

  describe('buildExistingVariantKeys', () => {
    it('returns empty set for empty families', () => {
      expect(buildExistingVariantKeys([])).toEqual(new Set());
    });

    it('builds one key for single weight/style entry', () => {
      const keys = buildExistingVariantKeys([
        { name: 'Inter', weights: ['400'], styles: ['normal'] },
      ]);
      expect(keys).toEqual(new Set([variantKey('Inter', '400', 'normal')]));
    });

    it('expands weights and styles arrays to cartesian product', () => {
      const keys = buildExistingVariantKeys([
        {
          name: 'Inter',
          provider: 'google',
          weights: ['400', '700'],
          styles: ['normal'],
        },
      ]);
      expect(keys).toEqual(
        new Set([
          variantKey('Inter', '400', 'normal'),
          variantKey('Inter', '700', 'normal'),
        ]),
      );
    });

    it('expands multiple styles', () => {
      const keys = buildExistingVariantKeys([
        {
          name: 'Inter',
          weights: ['400'],
          styles: ['normal', 'italic'],
        },
      ]);
      expect(keys).toEqual(
        new Set([
          variantKey('Inter', '400', 'normal'),
          variantKey('Inter', '400', 'italic'),
        ]),
      );
    });

    it('adds rangeMin key for weight range so variable font matches', () => {
      const keys = buildExistingVariantKeys([
        {
          name: 'Montserrat Variable',
          weights: ['100 900'],
          styles: ['normal'],
        },
      ]);
      expect(keys).toContain(
        variantKey('Montserrat Variable', '100 900', 'normal'),
      );
      expect(keys).toContain(
        variantKey('Montserrat Variable', '100', 'normal'),
      );
    });
  });

  describe('buildPrimaryVariantKeys', () => {
    it('does not add range-minimum alias keys', () => {
      const keys = buildPrimaryVariantKeys([
        {
          name: 'Montserrat Variable',
          weights: ['100 900'],
          styles: ['normal'],
        },
      ]);
      expect(keys).toEqual(
        new Set([variantKey('Montserrat Variable', '100 900', 'normal')]),
      );
    });
  });

  describe('pullFonts', () => {
    let tmpDir: string;
    let api: ApiService;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'font-pull-test-'));
      api = {
        getBrandKit: vi.fn(),
        downloadFile: vi.fn().mockResolvedValue(Buffer.from([0x00, 0x01])),
      } as unknown as ApiService;
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    it('returns empty result when Brand Kit has no fonts', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [],
      });

      const result = await pullFonts(api, tmpDir, undefined);

      expect(result.downloaded).toEqual([]);
      expect(result.skipped).toBe(0);
      expect(result.count).toBe(0);
      expect(api.downloadFile).not.toHaveBeenCalled();
    });

    it('throws when API returns a font with invalid or missing format', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'BadFormat',
            uri: 'public://canvas/font.woff2',
            format: '',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/font.woff2',
          },
        ],
      });

      await expect(pullFonts(api, tmpDir, undefined)).rejects.toThrow(
        /Invalid or missing font format for \/sites\/default\/files\/font\.woff2: API returned format "". Expected one of: woff2, woff, ttf, otf\./,
      );
      expect(api.downloadFile).not.toHaveBeenCalled();
    });

    it('throws when API returns a font with unknown format', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'UnknownFormat',
            uri: 'public://canvas/font.xyz',
            format: 'xyz',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/font.xyz',
          },
        ],
      });

      await expect(pullFonts(api, tmpDir, undefined)).rejects.toThrow(
        /Invalid or missing font format for \/sites\/default\/files\/font\.xyz: API returned format "xyz". Expected one of: woff2, woff, ttf, otf\./,
      );
      expect(api.downloadFile).not.toHaveBeenCalled();
    });

    it('skips variants already in config', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'Inter',
            uri: 'public://canvas/font.woff2',
            format: 'woff2',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/font.woff2',
          },
        ],
      });

      const existingConfig = {
        families: [
          {
            name: 'Inter',
            src: 'fonts/inter.woff2',
            weights: ['400'],
            styles: ['normal'],
          },
        ],
      };

      await fs.mkdir(path.join(tmpDir, 'fonts'), { recursive: true });
      await fs.writeFile(
        path.join(tmpDir, 'fonts/inter.woff2'),
        Buffer.from([0x00]),
        'utf-8',
      );

      const result = await pullFonts(api, tmpDir, existingConfig);

      expect(result.downloaded).toEqual([]);
      expect(result.skipped).toBe(1);
      expect(result.count).toBe(0);
      expect(api.downloadFile).not.toHaveBeenCalled();
    });

    it('downloads when config lists a local src variant but the file is missing', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'Inter',
            uri: 'public://canvas/font.woff2',
            format: 'woff2',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/font.woff2',
          },
        ],
      });

      const existingConfig = {
        families: [
          {
            name: 'Inter',
            src: 'fonts/missing.woff2',
            weights: ['400'],
            styles: ['normal'],
          },
        ],
      };

      const result = await pullFonts(api, tmpDir, existingConfig);

      expect(result.downloaded).toHaveLength(1);
      expect(result.skipped).toBe(0);
      expect(api.downloadFile).toHaveBeenCalledWith(
        '/sites/default/files/font.woff2',
      );
    });

    it('downloads only new variant when family exists with different weight', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'Inter',
            uri: 'public://canvas/inter-400.woff2',
            format: 'woff2',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/inter-400.woff2',
          },
          {
            id: '2',
            family: 'Inter',
            uri: 'public://canvas/inter-700.woff2',
            format: 'woff2',
            weight: '700',
            style: 'normal',
            url: '/sites/default/files/inter-700.woff2',
          },
        ],
      });

      const existingConfig = {
        families: [
          {
            name: 'Inter',
            src: 'fonts/inter-400.woff2',
            weights: ['400'],
            styles: ['normal'],
          },
        ],
      };

      await fs.mkdir(path.join(tmpDir, 'fonts'), { recursive: true });
      await fs.writeFile(
        path.join(tmpDir, 'fonts/inter-400.woff2'),
        Buffer.from([0x00]),
        'utf-8',
      );

      const result = await pullFonts(api, tmpDir, existingConfig);

      expect(result.downloaded).toHaveLength(1);
      expect(result.downloaded[0]).toEqual({
        name: 'Inter',
        src: 'fonts/inter-700-normal.woff2',
        weights: ['700'],
        styles: ['normal'],
      });
      expect(result.skipped).toBe(1);
      expect(result.count).toBe(1);
      expect(api.downloadFile).toHaveBeenCalledTimes(1);
      expect(api.downloadFile).toHaveBeenCalledWith(
        '/sites/default/files/inter-700.woff2',
      );

      const fontPath = path.join(tmpDir, 'fonts', 'inter-700-normal.woff2');
      await expect(fs.access(fontPath)).resolves.toBeUndefined();
    });

    it('downloads new fonts and returns correct FontFamilyEntry[]', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'My Font',
            uri: 'public://canvas/myfont.woff2',
            format: 'woff2',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/myfont.woff2',
          },
        ],
      });

      const result = await pullFonts(api, tmpDir, undefined);

      expect(result.downloaded).toEqual([
        {
          name: 'My Font',
          src: 'fonts/my-font-400-normal.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
      ]);
      expect(result.count).toBe(1);
      const fontPath = path.join(tmpDir, 'fonts', 'my-font-400-normal.woff2');
      await expect(fs.access(fontPath)).resolves.toBeUndefined();
    });

    it('normalizes spaces to dashes in downloaded filenames', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'Source Sans 3',
            uri: 'public://canvas/source.woff2',
            format: 'woff2',
            weight: '600',
            style: 'semi condensed',
            url: '/sites/default/files/source.woff2',
          },
        ],
      });

      const result = await pullFonts(api, tmpDir, undefined);

      expect(result.downloaded).toHaveLength(1);
      expect(result.downloaded[0]).toEqual({
        name: 'Source Sans 3',
        src: 'fonts/source-sans-3-600-semi-condensed.woff2',
        weights: ['600'],
        styles: ['semi condensed'],
      });
      const fontPath = path.join(
        tmpDir,
        'fonts',
        'source-sans-3-600-semi-condensed.woff2',
      );
      await expect(fs.access(fontPath)).resolves.toBeUndefined();
    });

    it('skips entries without url', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'NoUrl',
            uri: 'public://canvas/nourl.woff2',
            format: 'woff2',
            weight: '400',
            style: 'normal',
          },
        ],
      } as BrandKit);

      const result = await pullFonts(api, tmpDir, undefined);

      expect(result.downloaded).toEqual([]);
      expect(result.skipped).toBe(1);
      expect(api.downloadFile).not.toHaveBeenCalled();
    });

    it('creates fonts directory if it does not exist', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'New',
            uri: 'public://canvas/new.woff2',
            format: 'woff2',
            weight: '400',
            style: 'normal',
            url: '/sites/default/files/new.woff2',
          },
        ],
      });

      await pullFonts(api, tmpDir, undefined);

      const fontsDir = path.join(tmpDir, 'fonts');
      const stat = await fs.stat(fontsDir);
      expect(stat.isDirectory()).toBe(true);
    });

    it('reconstructs variable font weight range from axes and outputs weights/styles arrays', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'Montserrat Variable',
            uri: 'public://canvas/montserrat.woff2',
            format: 'woff2',
            weight: '100',
            style: 'normal',
            url: '/sites/default/files/montserrat.woff2',
            axes: [{ tag: 'wght', min: 100, max: 900, default: 400 }],
          },
        ],
      });

      const result = await pullFonts(api, tmpDir, undefined);

      expect(result.downloaded).toHaveLength(1);
      expect(result.downloaded[0]).toEqual({
        name: 'Montserrat Variable',
        src: 'fonts/montserrat-variable-100-900-normal.woff2',
        weights: ['100 900'],
        styles: ['normal'],
      });
      expect(result.count).toBe(1);
    });

    it('skips variable font when existing config already has the range', async () => {
      vi.mocked(api.getBrandKit).mockResolvedValue({
        id: 'global',
        fonts: [
          {
            id: '1',
            family: 'Montserrat Variable',
            uri: 'public://canvas/montserrat.woff2',
            format: 'woff2',
            weight: '100 900',
            style: 'normal',
            url: '/sites/default/files/montserrat.woff2',
            axes: [{ tag: 'wght', min: 100, max: 900, default: 400 }],
          },
        ],
      });

      const existingConfig = {
        families: [
          {
            name: 'Montserrat Variable',
            weights: ['100 900'],
            styles: ['normal'],
          },
        ],
      };

      const result = await pullFonts(api, tmpDir, existingConfig);

      expect(result.downloaded).toEqual([]);
      expect(result.skipped).toBe(1);
      expect(api.downloadFile).not.toHaveBeenCalled();
    });
  });

  describe('readBrandKitConfig', () => {
    let tmpDir: string;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'font-pull-config-'));
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    it('returns null when file does not exist', async () => {
      const result = await readBrandKitConfig(tmpDir);
      expect(result).toBeNull();
    });

    it('throws when file is invalid JSON', async () => {
      await fs.writeFile(
        path.join(tmpDir, 'canvas.brand-kit.json'),
        'not json',
        'utf-8',
      );
      await expect(readBrandKitConfig(tmpDir)).rejects.toThrow(
        /Invalid JSON in canvas\.brand-kit\.json/,
      );
    });

    it('returns parsed fonts config when valid', async () => {
      const config = { fonts: { families: [] } };
      await fs.writeFile(
        path.join(tmpDir, 'canvas.brand-kit.json'),
        JSON.stringify(config),
        'utf-8',
      );
      const result = await readBrandKitConfig(tmpDir);
      expect(result).toEqual({ families: [] });
    });
  });

  describe('updateBrandKitConfig', () => {
    let tmpDir: string;

    beforeEach(async () => {
      tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'font-pull-update-'));
    });

    afterEach(async () => {
      await fs.rm(tmpDir, { recursive: true, force: true });
    });

    it('does nothing when newFamilies is empty', async () => {
      await updateBrandKitConfig(tmpDir, []);
      const exists = await fs
        .access(path.join(tmpDir, 'canvas.brand-kit.json'))
        .then(() => true)
        .catch(() => false);
      expect(exists).toBe(false);
    });

    it('creates brand kit config file with fonts.families when it does not exist', async () => {
      const newFamilies: FontFamilyEntry[] = [
        {
          name: 'Inter',
          src: 'fonts/inter-400-normal.woff2',
          weights: ['400'],
          styles: ['normal'],
        },
      ];

      await updateBrandKitConfig(tmpDir, newFamilies);

      const raw = await fs.readFile(
        path.join(tmpDir, 'canvas.brand-kit.json'),
        'utf-8',
      );
      const parsed = JSON.parse(raw) as {
        fonts: { families: FontFamilyEntry[] };
      };
      expect(parsed.fonts.families).toEqual(newFamilies);
    });

    it('appends new families and preserves existing content', async () => {
      const existing = {
        fonts: {
          families: [
            {
              name: 'Existing',
              src: 'fonts/existing.woff2',
              weights: ['400'],
              styles: ['normal'],
            },
          ],
        },
      };
      await fs.writeFile(
        path.join(tmpDir, 'canvas.brand-kit.json'),
        JSON.stringify(existing, null, 2),
        'utf-8',
      );

      const newFamilies: FontFamilyEntry[] = [
        {
          name: 'New',
          src: 'fonts/new-700-normal.woff2',
          weights: ['700'],
          styles: ['normal'],
        },
      ];

      await updateBrandKitConfig(tmpDir, newFamilies);

      const raw = await fs.readFile(
        path.join(tmpDir, 'canvas.brand-kit.json'),
        'utf-8',
      );
      const parsed = JSON.parse(raw) as {
        fonts: { families: FontFamilyEntry[] };
      };
      expect(parsed.fonts.families).toHaveLength(2);
      expect(parsed.fonts.families[0]).toEqual(existing.fonts.families[0]);
      expect(parsed.fonts.families[1]).toEqual(newFamilies[0]);
    });
  });
});
