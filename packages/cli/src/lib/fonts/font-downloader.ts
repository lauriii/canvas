import fs from 'fs/promises';
import os from 'os';
import path from 'path';

import { FONT_EXTENSIONS, normalizeFontFormat } from './font-extensions.js';

import type { FontFaceData, RemoteFontSource } from 'unifont';

function isRemoteSource(
  src: FontFaceData['src'][number],
): src is RemoteFontSource {
  return (
    typeof src === 'object' &&
    'url' in src &&
    typeof (src as RemoteFontSource).url === 'string'
  );
}

/**
 * Picks the best remote URL from a font face.
 */
function pickRemoteSource(
  face: FontFaceData,
): { url: string; format: string } | null {
  const remotes = face.src.filter(isRemoteSource);
  for (const format of FONT_EXTENSIONS) {
    const remote = remotes.find((r) => {
      const normalized = normalizeFontFormat(r.format);
      return normalized === format;
    });
    if (remote?.url) {
      return { url: remote.url, format };
    }
  }
  const first = remotes[0];
  if (first?.url) {
    const normalized = normalizeFontFormat(first.format);
    if (normalized) {
      return { url: first.url, format: normalized };
    }
    throw new Error(
      `Cannot determine font format for ${first.url}: provider returned format "${first.format ?? '(missing)'}". Expected one of: ${FONT_EXTENSIONS.join(', ')}.`,
    );
  }
  return null;
}

/**
 * Downloads a font from a URL to a temporary file and returns the file path.
 *
 * Caller should delete the file when done (or use fs.unlink in a finally).
 */
export async function downloadFontToTemp(
  url: string,
  extension: string = 'woff2',
): Promise<string> {
  const response = await fetch(url);
  if (!response.ok) {
    throw new Error(
      `Failed to download font: ${response.status} ${response.statusText}`,
    );
  }
  const buffer = Buffer.from(await response.arrayBuffer());
  const tmpDir = os.tmpdir();
  const name = `canvas-font-${Date.now()}-${Math.random().toString(36).slice(2)}.${extension}`;
  const filePath = path.join(tmpDir, name);
  await fs.writeFile(filePath, buffer);
  return filePath;
}

export interface DownloadedFace {
  face: FontFaceData;
  weight: string;
  style: string;
  tempPath: string;
}

/**
 * For each resolved font face, download the best remote URL to a temp file.
 */
export async function downloadResolvedFaces(
  fonts: FontFaceData[],
): Promise<DownloadedFace[]> {
  const results: DownloadedFace[] = [];

  for (const face of fonts) {
    const picked = pickRemoteSource(face);
    if (!picked) continue;

    const ext = picked.format;
    const weight =
      face.weight === undefined
        ? '400'
        : Array.isArray(face.weight)
          ? face.weight.join(' ')
          : String(face.weight);
    const style = face.style ?? 'normal';

    const tempPath = await downloadFontToTemp(picked.url, ext);
    results.push({ face, weight, style, tempPath });
  }

  return results;
}
