import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  downloadFontToTemp,
  downloadResolvedFaces,
} from './font-downloader.js';

import type { FontFaceData } from 'unifont';

function face(
  src: Array<{ url: string; format: string }>,
  weight?: string | number | [number, number],
  style?: string,
): FontFaceData {
  return {
    fontFamily: 'Test',
    src,
    weight: weight ?? '400',
    style: style ?? 'normal',
  } as FontFaceData;
}

describe('font-downloader', () => {
  describe('downloadResolvedFaces', () => {
    beforeEach(() => {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
          ok: true,
          arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
        }),
      );
    });

    afterEach(() => {
      vi.unstubAllGlobals();
    });

    it('picks woff2 when multiple formats exist (FONT_EXTENSIONS order)', async () => {
      const fonts: FontFaceData[] = [
        face([
          { url: 'https://example.com/font.woff', format: 'woff' },
          { url: 'https://example.com/font.woff2', format: 'woff2' },
        ]),
      ];

      const results = await downloadResolvedFaces(fonts);

      expect(results).toHaveLength(1);
      expect(results[0].weight).toBe('400');
      expect(results[0].style).toBe('normal');
      expect(fetch).toHaveBeenCalledWith('https://example.com/font.woff2');
      expect(results[0].tempPath).toMatch(/\.woff2$/);
    });

    it('picks woff when woff2 is not available', async () => {
      const fonts: FontFaceData[] = [
        face([{ url: 'https://example.com/font.woff', format: 'woff' }]),
      ];

      const results = await downloadResolvedFaces(fonts);

      expect(results).toHaveLength(1);
      expect(fetch).toHaveBeenCalledWith('https://example.com/font.woff');
      expect(results[0].tempPath).toMatch(/\.woff$/);
    });

    it('picks format in FONT_EXTENSIONS order (ttf before otf when only those)', async () => {
      const fonts: FontFaceData[] = [
        face([
          { url: 'https://example.com/font.otf', format: 'otf' },
          { url: 'https://example.com/font.ttf', format: 'ttf' },
        ]),
      ];

      const results = await downloadResolvedFaces(fonts);

      expect(results).toHaveLength(1);
      expect(fetch).toHaveBeenCalledWith('https://example.com/font.ttf');
      expect(results[0].tempPath).toMatch(/\.ttf$/);
    });

    it('normalizes variable font formats (woff2-variations -> woff2)', async () => {
      const fonts: FontFaceData[] = [
        face([
          {
            url: 'https://example.com/font.woff2',
            format: 'woff2-variations',
          },
        ]),
      ];

      const results = await downloadResolvedFaces(fonts);

      expect(results).toHaveLength(1);
      expect(fetch).toHaveBeenCalledWith('https://example.com/font.woff2');
      expect(results[0].tempPath).toMatch(/\.woff2$/);
    });

    it('skips face when src has no remote URLs', async () => {
      const fonts: FontFaceData[] = [
        face([]),
        face([{ url: 'https://example.com/font.woff2', format: 'woff2' }]),
      ];

      const results = await downloadResolvedFaces(fonts);

      expect(results).toHaveLength(1);
      expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('throws when provider returns format not in FONT_EXTENSIONS', async () => {
      const fonts: FontFaceData[] = [
        face([{ url: 'https://example.com/font.xyz', format: 'xyz' }]),
      ];

      await expect(downloadResolvedFaces(fonts)).rejects.toThrow(
        /Cannot determine font format for https:\/\/example\.com\/font\.xyz: provider returned format "xyz"\. Expected one of: woff2, woff, ttf, otf\./,
      );
      expect(fetch).not.toHaveBeenCalled();
    });

    it('throws when provider returns missing format', async () => {
      const fonts: FontFaceData[] = [
        face([
          {
            url: 'https://example.com/font.woff2',
            format: '' as string,
          },
        ]),
      ];

      await expect(downloadResolvedFaces(fonts)).rejects.toThrow(
        /Cannot determine font format.*Expected one of: woff2, woff, ttf, otf\./,
      );
      expect(fetch).not.toHaveBeenCalled();
    });

    it('writes downloaded file to temp path', async () => {
      const buffer = new Uint8Array([1, 2, 3]);
      vi.mocked(fetch).mockResolvedValue({
        ok: true,
        arrayBuffer: () => Promise.resolve(buffer.buffer),
      } as Response);

      const fonts: FontFaceData[] = [
        face([{ url: 'https://example.com/font.woff2', format: 'woff2' }]),
      ];

      const results = await downloadResolvedFaces(fonts);

      expect(results).toHaveLength(1);
      await expect(fs.access(results[0].tempPath)).resolves.toBeUndefined();
      const content = await fs.readFile(results[0].tempPath);
      expect(content.equals(Buffer.from(buffer))).toBe(true);
      await fs.rm(results[0].tempPath, { force: true });
    });
  });

  describe('downloadFontToTemp', () => {
    it('throws when fetch returns not ok', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
          ok: false,
          status: 404,
          statusText: 'Not Found',
        }),
      );

      await expect(
        downloadFontToTemp('https://example.com/missing.woff2', 'woff2'),
      ).rejects.toThrow('Failed to download font: 404 Not Found');

      vi.unstubAllGlobals();
    });

    it('writes file with correct extension and returns path', async () => {
      vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
          ok: true,
          arrayBuffer: () => Promise.resolve(new ArrayBuffer(0)),
        }),
      );

      const filePath = await downloadFontToTemp(
        'https://example.com/font.woff2',
        'woff2',
      );

      expect(filePath).toMatch(/\.woff2$/);
      expect(filePath).toContain(path.join(os.tmpdir(), ''));
      await expect(fs.access(filePath)).resolves.toBeUndefined();
      await fs.rm(filePath, { force: true });
      vi.unstubAllGlobals();
    });
  });
});
