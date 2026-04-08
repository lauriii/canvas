import fs from 'fs/promises';
import path from 'path';

import { BRAND_KIT_CONFIG_FILENAME } from '../../config.js';
import { FONT_EXTENSIONS, normalizeFontFormat } from './font-extensions.js';

import type { FontFamilyEntry, FontsConfig } from '../../config.js';
import type { ApiService } from '../../services/api.js';

const FONTS_DIR = 'fonts';

/**
 * Validates the font format from the API response and returns it normalized.
 * The backend always sends format per FontEntry; we throw if it is missing or
 * not a known extension so the backend can be fixed rather than guessing.
 */
function validateFontFormat(entryFormat: string, url: string): string {
  const normalized = normalizeFontFormat(entryFormat);
  if (normalized) {
    return normalized;
  }
  throw new Error(
    `Invalid or missing font format for ${url}: API returned format "${entryFormat}". Expected one of: ${FONT_EXTENSIONS.join(', ')}.`,
  );
}

/**
 * Normalizes family, weight, and style into a single comparable key.
 */
export function variantKey(
  family: string,
  weight: string | number,
  style: string | number,
): string {
  const f = String(family || '')
    .trim()
    .toLowerCase();
  const w =
    String(weight ?? '400')
      .trim()
      .toLowerCase() || '400';
  const s =
    String(style ?? 'normal')
      .trim()
      .toLowerCase() || 'normal';
  return `${f}\0${w}\0${s}`;
}

function toWeightStrings(entry: FontFamilyEntry): string[] {
  if (entry.weights?.length) {
    return entry.weights.map((w) => String(w).trim().toLowerCase());
  }
  return ['400'];
}

function toStyleStrings(entry: FontFamilyEntry): string[] {
  if (entry.styles?.length) {
    return entry.styles.map((s) => String(s).trim().toLowerCase());
  }
  return ['normal'];
}

/**
 * Expands config families into a set of variant keys (family + weight + style).
 * Provider-based entries with weights/styles arrays are expanded to the cartesian product.
 */
export function buildExistingVariantKeys(
  families: FontFamilyEntry[],
): Set<string> {
  const keys = new Set<string>();

  for (const entry of families) {
    const family = String(entry.name || '')
      .trim()
      .toLowerCase();
    if (!family) continue;

    const weights = toWeightStrings(entry);
    const styles = toStyleStrings(entry);

    for (const w of weights) {
      for (const s of styles) {
        keys.add(variantKey(entry.name, w, s));
        if (w.includes(' ')) {
          const rangeMin = w.split(/\s+/)[0];
          if (rangeMin) keys.add(variantKey(entry.name, rangeMin, s));
        }
      }
    }
  }

  return keys;
}

/**
 * Variant keys from config for one row per configured variant (weights × styles).
 * Unlike {@link buildExistingVariantKeys}, does not add the extra range-minimum
 * alias keys used for pull/push matching against API weight shapes.
 */
export function buildPrimaryVariantKeys(
  families: FontFamilyEntry[],
): Set<string> {
  const keys = new Set<string>();

  for (const entry of families) {
    const family = String(entry.name || '')
      .trim()
      .toLowerCase();
    if (!family) continue;

    const weights = toWeightStrings(entry);
    const styles = toStyleStrings(entry);

    for (const w of weights) {
      for (const s of styles) {
        keys.add(variantKey(entry.name, w, s));
      }
    }
  }

  return keys;
}

/**
 * Parses a key produced by {@link variantKey} back into family, weight, and style.
 * Values are normalized (lowercase) as stored in the key.
 */
export function parseVariantKey(key: string): {
  family: string;
  weight: string;
  style: string;
} {
  const [family = '', weight = '400', style = 'normal'] = key.split('\0');
  return { family, weight, style };
}

/**
 * Returns true when the remote variant is already represented locally: the variant
 * key appears in config, and any local `src` file backing that variant exists on disk.
 * Provider-only config entries (no `src`) count as satisfied when the key matches.
 */
export async function isRemoteVariantAlreadyPulled(
  projectRoot: string,
  families: FontFamilyEntry[],
  remoteFamily: string,
  weight: string,
  style: string,
): Promise<boolean> {
  const key = variantKey(remoteFamily, weight, style);
  const keys = buildExistingVariantKeys(families);
  if (!keys.has(key)) {
    return false;
  }

  let matchedLocalWithSrc = false;
  for (const entry of families) {
    if (!('src' in entry) || !entry.src) {
      continue;
    }
    const entryKeys = buildExistingVariantKeys([entry]);
    if (!entryKeys.has(key)) {
      continue;
    }
    matchedLocalWithSrc = true;
    const abs = path.resolve(projectRoot, entry.src);
    try {
      await fs.access(abs);
      return true;
    } catch {
      // This family entry claims the variant but the file is missing.
    }
  }

  if (matchedLocalWithSrc) {
    return false;
  }

  return true;
}

/**
 * Slugifies a string for use in file names.
 */
export function slugify(value: string): string {
  return String(value)
    .trim()
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/[^a-z0-9-]/g, '');
}

export interface PullFontsResult {
  downloaded: FontFamilyEntry[];
  skipped: number;
  count: number;
}

/**
 * Pull fonts from the global Brand Kit: download new variants and return config entries.
 * Variants already represented in existingFontsConfig are skipped (variant-level matching).
 */
export async function pullFonts(
  api: ApiService,
  projectRoot: string,
  existingFontsConfig: FontsConfig | undefined,
): Promise<PullFontsResult> {
  const brandKit = await api.getBrandKit();
  const remoteFonts = brandKit.fonts ?? [];

  const fontsDir = path.resolve(projectRoot, FONTS_DIR);
  const downloaded: FontFamilyEntry[] = [];
  let skipped = 0;

  for (const entry of remoteFonts) {
    let weight = entry.weight || '400';
    // Reconstruct variable font weight range from axes if the stored
    // weight is a single value but the font has a wght axis range.
    if (entry.axes?.length && !weight.includes(' ')) {
      const wghtAxis = entry.axes.find((a) => a.tag === 'wght');
      if (
        wghtAxis?.min != null &&
        wghtAxis?.max != null &&
        wghtAxis.min !== wghtAxis.max
      ) {
        weight = `${wghtAxis.min} ${wghtAxis.max}`;
      }
    }

    const style = entry.style || 'normal';
    if (
      await isRemoteVariantAlreadyPulled(
        projectRoot,
        existingFontsConfig?.families ?? [],
        entry.family,
        weight,
        style,
      )
    ) {
      skipped++;
      continue;
    }

    const url = entry.url;
    if (!url || typeof url !== 'string') {
      skipped++;
      continue;
    }

    const slug = slugify(entry.family);
    const format = validateFontFormat(entry.format, url);
    const fileName = `${slug}-${slugify(weight)}-${slugify(style)}.${slugify(format)}`;
    const localPath = path.join(fontsDir, fileName);
    const relativeSrc = `${FONTS_DIR}/${fileName}`;

    const buffer = await api.downloadFile(url);
    await fs.mkdir(fontsDir, { recursive: true });
    await fs.writeFile(localPath, buffer);

    downloaded.push({
      name: entry.family,
      src: relativeSrc,
      weights: [weight],
      styles: [style],
    });
  }

  return {
    downloaded,
    skipped,
    count: downloaded.length,
  };
}

/**
 * Reads the fonts block from canvas.brand-kit.json. Returns null if the file is missing.
 * Throws if the file exists but contains invalid JSON.
 */
export async function readBrandKitConfig(
  projectRoot: string,
): Promise<FontsConfig | null> {
  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let raw: string;
  try {
    raw = await fs.readFile(configPath, 'utf-8');
  } catch (err) {
    // Brand kit config is optional; return null when the file is missing.
    if (
      err &&
      typeof err === 'object' &&
      'code' in err &&
      err.code === 'ENOENT'
    ) {
      return null;
    }
    throw err;
  }
  let parsed: { fonts?: FontsConfig };
  try {
    parsed = JSON.parse(raw) as { fonts?: FontsConfig };
  } catch (err) {
    const message =
      err instanceof SyntaxError
        ? err.message
        : err instanceof Error
          ? err.message
          : String(err);
    throw new Error(`Invalid JSON in ${BRAND_KIT_CONFIG_FILENAME}: ${message}`);
  }
  const fonts = parsed?.fonts;
  if (fonts && typeof fonts === 'object' && Array.isArray(fonts.families)) {
    return fonts;
  }
  return null;
}

/**
 * Merges new font family entries into canvas.brand-kit.json (fonts.families) and writes it back.
 * Preserves other top-level keys. Creates the file with only { fonts: { families } } if it does not exist.
 */
export async function updateBrandKitConfig(
  projectRoot: string,
  newFamilies: FontFamilyEntry[],
): Promise<void> {
  if (newFamilies.length === 0) return;

  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let fileContent: Record<string, unknown> = {};
  try {
    const raw = await fs.readFile(configPath, 'utf-8');
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      fileContent = parsed as Record<string, unknown>;
    }
  } catch {
    // File missing or invalid; start with empty object.
  }

  const existingFonts = (fileContent.fonts as FontsConfig | undefined) ?? {
    families: [],
  };
  const mergedFamilies = [
    ...(Array.isArray(existingFonts.families) ? existingFonts.families : []),
    ...newFamilies,
  ];
  const nextFonts: FontsConfig = {
    ...existingFonts,
    families: mergedFamilies,
  };

  await fs.writeFile(
    configPath,
    `${JSON.stringify({ ...fileContent, fonts: nextFonts }, null, 2)}\n`,
    'utf-8',
  );
}
