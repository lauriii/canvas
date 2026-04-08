import { randomUUID } from 'crypto';
import fs from 'fs/promises';
import path from 'path';

import { downloadResolvedFaces } from './font-downloader.js';
import { extractVariableFontAxes } from './font-metadata.js';
import {
  buildExistingVariantKeys,
  buildPrimaryVariantKeys,
  parseVariantKey,
  slugify,
  variantKey,
} from './font-pull.js';
import { createFontResolver } from './font-resolver.js';
import { validateFontsConfig } from './font-validate.js';

import type { FontFaceData, ResolveFontOptions } from 'unifont';
import type { Config, FontFamilyEntry, FontsConfig } from '../../config.js';
import type { ApiService } from '../../services/api.js';
import type {
  BrandKitFontAxis,
  BrandKitFontEntry,
} from '../../types/Component.js';
import type { Result } from '../../types/Result.js';
import type { DownloadedFace } from './font-downloader.js';

/** Human-readable names for common OpenType variable font axis tags (CSS axes UI). */
const AXIS_TAG_DISPLAY_NAMES: Record<string, string> = {
  wght: 'Weight',
  wdth: 'Width',
  opsz: 'Optical size',
  ital: 'Italic',
  slnt: 'Slant',
  grd: 'Grade',
  wonk: 'Wonky',
};

/**
 * Enriches variable font axes for the Brand Kit payload: adds human-readable
 * names for known tags (so the UI shows "Weight" not "wght") and applies
 * optional axis default overrides from config, clamped to each axis min/max.
 */
function enrichVariableFontAxes(
  axes: BrandKitFontAxis[] | null | undefined,
  axisDefaults?: Record<string, number>,
): BrandKitFontAxis[] | undefined {
  if (!axes?.length) return undefined;
  return axes.map((axis) => {
    const min = axis.min ?? 0;
    const max = axis.max ?? 0;
    const override = axisDefaults?.[axis.tag];
    const defaultVal =
      override !== undefined && override !== null
        ? Math.min(max, Math.max(min, Number(override)))
        : axis.default;
    return {
      ...axis,
      name: axis.name ?? AXIS_TAG_DISPLAY_NAMES[axis.tag],
      default: defaultVal,
    };
  });
}

/**
 * Builds a map of variant key (family+weight+style) to existing Brand Kit font entry.
 * Used to skip uploading variants that already exist on the backend.
 */
function buildExistingVariantMap(
  fonts: BrandKitFontEntry[] | undefined | null,
): Map<string, BrandKitFontEntry> {
  const map = new Map<string, BrandKitFontEntry>();
  if (!fonts?.length) return map;
  for (const entry of fonts) {
    const key = variantKey(entry.family, entry.weight, entry.style);
    map.set(key, entry);
  }
  return map;
}

/**
 * Normalizes weights from config: uses weights array or falls back to defaults.
 */
function normalizeWeights(
  entry: FontFamilyEntry,
  defaults: FontsConfig['defaults'],
): string[] {
  if (entry.weights?.length) {
    return entry.weights.map((w) => String(w).trim().toLowerCase());
  }
  return (defaults?.weights ?? ['400']).map((v) =>
    String(v).trim().toLowerCase(),
  );
}

/**
 * Normalizes styles from config: uses styles array or falls back to defaults.
 */
function normalizeStyles(
  entry: FontFamilyEntry,
  defaults: FontsConfig['defaults'],
): ResolveFontOptions['styles'] {
  if (entry.styles?.length) {
    return entry.styles.map((s) => s) as ResolveFontOptions['styles'];
  }
  return (defaults?.styles ?? ['normal']) as ResolveFontOptions['styles'];
}

function mergeResolveOptions(
  defaults: FontsConfig['defaults'],
  entry: FontFamilyEntry,
): ResolveFontOptions {
  const weights = normalizeWeights(entry, defaults);
  const styles = normalizeStyles(entry, defaults);
  // When the family does not set subsets, use a single subset only. Otherwise
  // defaults or provider defaults can pull in many subsets (e.g. 100+ for CJK).
  const subsets =
    entry.subsets && entry.subsets.length > 0 ? entry.subsets : ['latin'];
  // Pass only the options we need; unifont merges with its defaultResolveOptions.
  // This avoids requesting unnecessary variants.
  return {
    weights,
    styles,
    subsets,
    formats: ['woff2'],
  };
}

/**
 * Process one family entry: resolve (unifont or local src), then upload and build font entries.
 * Reuses existing variant entries from existingByKey when the variant key matches (no upload).
 */
async function processFamily(
  entry: FontFamilyEntry,
  config: FontsConfig,
  projectRoot: string,
  api: ApiService,
  unifont: Awaited<ReturnType<typeof createFontResolver>>,
  existingByKey: Map<string, BrandKitFontEntry>,
): Promise<BrandKitFontEntry[]> {
  const results: BrandKitFontEntry[] = [];

  if (entry.src) {
    // Local file: check existing, then read from disk, extract axes, upload if new.
    const weight = entry.weights?.[0] ?? '400';
    const style = entry.styles?.[0] ?? 'normal';
    const key = variantKey(entry.name, weight, style);
    const existing = existingByKey.get(key);
    if (existing) {
      results.push(existing);
      return results;
    }
    const absolutePath = path.resolve(projectRoot, entry.src);
    try {
      await fs.access(absolutePath);
    } catch {
      throw new Error(`Font file not found: ${entry.src}`);
    }
    const format = path.extname(entry.src).slice(1).toLowerCase() || 'woff2';
    const axes = await extractVariableFontAxes(absolutePath);
    const slugifiedFilename = `${slugify(entry.name)}-${slugify(weight)}-${slugify(style)}.${format}`;
    const uploadResult = await api.uploadFont(absolutePath, slugifiedFilename);
    results.push({
      id: randomUUID(),
      family: entry.name,
      uri: uploadResult.uri,
      format,
      weight,
      style,
      axes: enrichVariableFontAxes(axes, entry.axisDefaults) ?? undefined,
    });
    return results;
  }

  // Provider-based: resolve via unifont, then filter to only requested (weight, style) and download.
  const options = mergeResolveOptions(config.defaults, entry);
  const resolved = await unifont.resolveFont(entry.name, options);
  if (!resolved?.fonts?.length) {
    throw new Error(
      `Could not resolve font "${entry.name}" with provider ${entry.provider ?? 'any'}.`,
    );
  }

  const requestedVariantKeys = new Set<string>();
  for (const w of options.weights) {
    for (const s of options.styles) {
      requestedVariantKeys.add(variantKey(entry.name, w, s));
      // Variable font faces from providers (e.g. npm @fontsource-variable) report weight as [min, max]; we take weight[0]. So a config range "100 900" must also match the face key with weight "100".
      if (typeof w === 'string' && w.includes(' ')) {
        const rangeMin = w.split(/\s+/)[0];
        if (rangeMin) {
          requestedVariantKeys.add(variantKey(entry.name, rangeMin, s));
        }
      }
    }
  }

  const weightFromFace = (face: FontFaceData): string =>
    face.weight === undefined
      ? '400'
      : Array.isArray(face.weight)
        ? face.weight.join(' ')
        : String(face.weight);
  const styleFromFace = (face: FontFaceData): string => face.style ?? 'normal';

  const facesMatchingRequest = resolved.fonts.filter((face) =>
    requestedVariantKeys.has(
      variantKey(entry.name, weightFromFace(face), styleFromFace(face)),
    ),
  );

  // One face per (weight, style); unifont may return many per key (e.g. one per subset).
  const oneFacePerVariant = new Map<string, FontFaceData>();
  for (const face of facesMatchingRequest) {
    const k = variantKey(entry.name, weightFromFace(face), styleFromFace(face));
    if (!oneFacePerVariant.has(k)) oneFacePerVariant.set(k, face);
  }
  const fontsToUse = Array.from(oneFacePerVariant.values());

  const downloaded: DownloadedFace[] = await downloadResolvedFaces(fontsToUse);
  const tempPaths: string[] = [];

  try {
    for (const d of downloaded) {
      tempPaths.push(d.tempPath);
      const key = variantKey(entry.name, d.weight, d.style);
      const existing = existingByKey.get(key);
      if (existing) {
        results.push(existing);
        continue;
      }
      // Only extract axes when unifont resolved a variable face (weight range).
      // Individual weights from providers may still be variable font files on disk.
      const isVariableFace = Array.isArray(d.face.weight);
      const axes = isVariableFace
        ? await extractVariableFontAxes(d.tempPath)
        : null;
      const uploadResult = await api.uploadFont(d.tempPath);
      results.push({
        id: randomUUID(),
        family: entry.name,
        uri: uploadResult.uri,
        format: 'woff2',
        weight: d.weight,
        style: d.style,
        axes: enrichVariableFontAxes(axes, entry.axisDefaults) ?? undefined,
      });
    }
  } finally {
    for (const p of tempPaths) {
      try {
        await fs.unlink(p);
      } catch {
        // Ignore cleanup errors.
      }
    }
  }

  return results;
}

/**
 * Normalizes a font entry to the payload shape the backend accepts (strips url, variantType).
 */
function toBrandKitFontPayload(
  entry: BrandKitFontEntry & { url?: string; variantType?: string },
): BrandKitFontEntry {
  const out: BrandKitFontEntry = {
    id: entry.id,
    family: entry.family,
    uri: entry.uri,
    format: entry.format,
    weight: entry.weight,
    style: entry.style,
  };
  if (entry.axes != null) out.axes = entry.axes;
  return out;
}

/**
 * Returns true if the two font arrays represent the same set of variants with the same URIs.
 */
function fontsUnchanged(
  current: BrandKitFontEntry[],
  remote: BrandKitFontEntry[] | undefined | null,
): boolean {
  if (!remote?.length && !current.length) return true;
  if (!remote?.length || !current.length) return false;
  if (current.length !== remote.length) return false;
  const remoteByKey = buildExistingVariantMap(remote);
  for (const entry of current) {
    const key = variantKey(entry.family, entry.weight, entry.style);
    const existing = remoteByKey.get(key);
    if (!existing || existing.uri !== entry.uri) return false;
  }
  return true;
}

export type FontPushOutcomeOperation =
  | 'create'
  | 'update'
  | 'unchanged'
  | 'delete';

export interface FontPushOutcome {
  itemName: string;
  operation: FontPushOutcomeOperation;
}

function computeFontPushOutcomes(
  allEntries: BrandKitFontEntry[],
  initialRemote: BrandKitFontEntry[] | undefined | null,
): FontPushOutcome[] {
  const initialByKey = buildExistingVariantMap(initialRemote);
  const pushedKeys = new Set(
    allEntries.map((e) => variantKey(e.family, e.weight, e.style)),
  );
  const outcomes: FontPushOutcome[] = [];

  for (const e of allEntries) {
    const key = variantKey(e.family, e.weight, e.style);
    const prev = initialByKey.get(key);
    let operation: Exclude<FontPushOutcomeOperation, 'delete'>;
    if (!prev) {
      operation = 'create';
    } else if (prev.uri === e.uri) {
      operation = 'unchanged';
    } else {
      operation = 'update';
    }
    outcomes.push({
      itemName: `${e.family} ${e.weight} ${e.style}`,
      operation,
    });
  }

  for (const r of initialRemote ?? []) {
    const key = variantKey(r.family, r.weight, r.style);
    if (!pushedKeys.has(key)) {
      outcomes.push({
        itemName: `${r.family} ${r.weight} ${r.style}`,
        operation: 'delete',
      });
    }
  }

  return outcomes;
}

/**
 * True if the remote Brand Kit already has a variant that corresponds to this
 * local plan key (handles API weight as range vs range minimum only).
 */
function remoteHasVariantForLocalPlanKey(
  localPlanKey: string,
  remoteKeySet: Set<string>,
): boolean {
  if (remoteKeySet.has(localPlanKey)) {
    return true;
  }
  const { family, weight, style } = parseVariantKey(localPlanKey);
  if (weight.includes(' ')) {
    const rangeMin = weight.split(/\s+/)[0];
    if (rangeMin) {
      return remoteKeySet.has(variantKey(family, rangeMin, style));
    }
  }
  return false;
}

/**
 * Builds planned font rows for the push confirmation table (local keys vs remote Brand Kit).
 */
export function buildFontPushPlannedResults(
  fontsConfig: FontsConfig | undefined,
  remoteFonts: BrandKitFontEntry[],
  operationLabels: { create: string; update: string; delete: string },
): Result[] {
  const families = fontsConfig?.families ?? [];
  const localKeysFull = buildExistingVariantKeys(families);
  const localKeysPrimary = buildPrimaryVariantKeys(families);
  const remoteKeySet = new Set(
    remoteFonts.map((rf) =>
      variantKey(rf.family, rf.weight ?? '400', rf.style ?? 'normal'),
    ),
  );

  const results: Result[] = [];

  for (const key of localKeysPrimary) {
    const { family, weight, style } = parseVariantKey(key);
    const itemName = `${family} ${weight} ${style}`;
    results.push({
      itemName,
      itemType: 'Font variant',
      success: true,
      details: [
        {
          content: remoteHasVariantForLocalPlanKey(key, remoteKeySet)
            ? operationLabels.update
            : operationLabels.create,
        },
      ],
    });
  }

  for (const rf of remoteFonts) {
    const key = variantKey(rf.family, rf.weight ?? '400', rf.style ?? 'normal');
    if (!localKeysFull.has(key)) {
      results.push({
        itemName: `${rf.family} ${rf.weight ?? '400'} ${rf.style ?? 'normal'}`,
        itemType: 'Font variant',
        success: true,
        details: [{ content: operationLabels.delete }],
      });
    }
  }

  return results;
}

/**
 * Push fonts from canvas.brand-kit.json (config.fonts) to the global Brand Kit.
 * Fetches existing Brand Kit first; skips uploading variants that already exist and skips PATCH when unchanged.
 */
export async function pushFonts(
  config: Config,
  api: ApiService,
): Promise<{
  count: number;
  skipped: number;
  deleted: number;
  outcomes: FontPushOutcome[];
}> {
  const fontsConfig = config.fonts;
  if (!fontsConfig) {
    return { count: 0, skipped: 0, deleted: 0, outcomes: [] };
  }
  if (!fontsConfig.families?.length) {
    let deleted = 0;
    let initialRemote: BrandKitFontEntry[] | undefined;
    try {
      const brandKit = await api.getBrandKit();
      initialRemote = brandKit.fonts ?? undefined;
      deleted = initialRemote?.length ?? 0;
    } catch {
      initialRemote = undefined;
    }
    await api.updateBrandKit({ fonts: [] });
    const outcomes = computeFontPushOutcomes([], initialRemote);
    return { count: 0, skipped: 0, deleted, outcomes };
  }

  const projectRoot = process.cwd();
  await validateFontsConfig(fontsConfig, projectRoot);

  let existingByKey = new Map<string, BrandKitFontEntry>();
  let remoteFonts: BrandKitFontEntry[] | undefined;
  try {
    const brandKit = await api.getBrandKit();
    remoteFonts = brandKit.fonts ?? undefined;
    existingByKey = buildExistingVariantMap(remoteFonts);
  } catch {
    // Brand Kit may not exist yet; proceed with uploads for all variants.
  }

  const unifont = await createFontResolver(fontsConfig, projectRoot);
  const allEntries: BrandKitFontEntry[] = [];

  for (const entry of fontsConfig.families) {
    const entries = await processFamily(
      entry,
      fontsConfig,
      projectRoot,
      api,
      unifont,
      existingByKey,
    );
    allEntries.push(...entries);
  }

  const skipped = allEntries.filter((e) =>
    existingByKey.has(variantKey(e.family, e.weight, e.style)),
  ).length;
  const count = allEntries.length - skipped;

  let deleted = 0;
  if (!fontsUnchanged(allEntries, remoteFonts)) {
    const pushedKeys = new Set(
      allEntries.map((e) => variantKey(e.family, e.weight, e.style)),
    );
    deleted = (remoteFonts ?? []).filter(
      (e) => !pushedKeys.has(variantKey(e.family, e.weight, e.style)),
    ).length;
    const payload = allEntries.map(toBrandKitFontPayload);
    await api.updateBrandKit({ fonts: payload });
  }

  const outcomes = computeFontPushOutcomes(allEntries, remoteFonts);
  return { count, skipped, deleted, outcomes };
}
