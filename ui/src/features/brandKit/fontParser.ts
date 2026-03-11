import { parse } from 'opentype.js';
import decompress from 'woff2-encoder/decompress';

import type { Font, LocalizedName } from 'opentype.js';
import type { AssetLibraryFont } from '@/types/CodeComponent';

type OpenTypeHeadTable = {
  macStyle?: number;
};

type OpenTypeOs2Table = {
  fsSelection?: number;
  usWeightClass?: number;
};

type OpenTypePostTable = {
  italicAngle?: number;
};

type OpenTypeVariationAxis = {
  tag: string;
  minValue: number;
  maxValue: number;
  defaultValue: number;
  name?: string | LocalizedName;
};

export type ParsedFont = Font & {
  tables: {
    fvar?: {
      axes?: OpenTypeVariationAxis[];
    };
    head?: OpenTypeHeadTable;
    os2?: OpenTypeOs2Table;
    post?: OpenTypePostTable;
  };
};

const toArrayBuffer = (value: ArrayBuffer | Uint8Array): ArrayBuffer => {
  const bytes =
    value instanceof ArrayBuffer
      ? new Uint8Array(value)
      : new Uint8Array(value.buffer, value.byteOffset, value.byteLength);

  return bytes.slice().buffer;
};

export const parseFontFile = async (
  file: File,
  format: AssetLibraryFont['format'],
): Promise<ParsedFont> => {
  const inputBuffer = await file.arrayBuffer();
  const parsedBuffer =
    format === 'woff2'
      ? toArrayBuffer(await decompress(inputBuffer))
      : inputBuffer;

  return parse(parsedBuffer) as ParsedFont;
};
