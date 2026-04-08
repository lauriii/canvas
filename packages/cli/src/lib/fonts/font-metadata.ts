import fs from 'fs/promises';
import { parse as parseFont } from 'opentype.js';
import decompress from 'woff2-encoder/decompress';

import type { BrandKitFontAxis } from '../../types/Component.js';

/** Parsed font with optional fvar (opentype.js 1.x may not expose fvar on all builds). */
interface ParsedFontWithFvar {
  tables?: {
    fvar?: {
      axes?: Array<{
        tag: string;
        minValue: number;
        maxValue: number;
        defaultValue: number;
      }>;
    };
  };
}

function toArrayBuffer(buffer: Buffer): ArrayBuffer {
  const u8 = new Uint8Array(buffer);
  return u8.buffer.slice(u8.byteOffset, u8.byteOffset + u8.byteLength);
}

/**
 * Extracts variable font axes (fvar table) from a font file path.
 * Returns null for static fonts or if parsing fails.
 */
export async function extractVariableFontAxes(
  filePath: string,
): Promise<BrandKitFontAxis[] | null> {
  const buffer = await fs.readFile(filePath);
  const ext = filePath.toLowerCase().split('.').pop();

  let parseBuffer: ArrayBuffer;
  if (ext === 'woff2') {
    try {
      const decompressed = await decompress(toArrayBuffer(buffer));
      parseBuffer =
        decompressed instanceof ArrayBuffer
          ? decompressed
          : (decompressed.buffer.slice(
              decompressed.byteOffset,
              decompressed.byteOffset + decompressed.byteLength,
            ) as ArrayBuffer);
    } catch {
      return null;
    }
  } else {
    parseBuffer = toArrayBuffer(buffer);
  }

  try {
    const font = parseFont(parseBuffer) as ParsedFontWithFvar;
    const axes = font.tables?.fvar?.axes;
    if (!axes?.length) return null;

    return axes.map((a) => ({
      tag: a.tag,
      min: a.minValue,
      max: a.maxValue,
      default: a.defaultValue,
    }));
  } catch {
    return null;
  }
}
