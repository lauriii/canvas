import fs from 'fs/promises';
import path from 'path';
import {
  BRAND_KIT_CONFIG_FILENAME,
  colorTokenValuesEqual,
  deriveColorName,
  normalizeBrandKitColors,
  serializeColorValue,
} from '@drupal-canvas/discovery';

import type {
  BrandKitColorFileObject,
  BrandKitColorFileValue,
  BrandKitColorsFileMap,
  NormalizedBrandKitColor,
} from '@drupal-canvas/discovery';
import type { BrandKitColorEntry } from '../../types/Component.js';

export interface ColorPullPlan {
  /** The colors map the file should contain after the pull. */
  colors: BrandKitColorsFileMap;
  /** Item names of colors newly added to the file. */
  added: string[];
  /** Item names of colors whose file entry was updated from the server. */
  updated: string[];
  /** Count of colors already represented in the file and left untouched. */
  unchanged: number;
  /** Item names of file entries with no matching color on the server. */
  localOnly: string[];
  /** Raw keys of dropped duplicate entries (same variable, first kept). */
  duplicates: string[];
  /** Whether the file's colors map needs to be written at all. */
  changed: boolean;
}

export interface ColorPullOptions {
  /** Keep existing local entries untouched; only append new server colors. */
  skipOverwrite?: boolean;
}

function itemName(name: string, cssVariable: string): string {
  return `${name} (${cssVariable})`;
}

/**
 * Serializes a server color into a hand-editable map entry: the plain CSS
 * string when lossless, wrapped in an object only when the color needs a
 * display name differing from the one its key derives (or, on a rewrite,
 * when the local entry had asserted a display format).
 */
function toFileValue(
  remote: BrandKitColorEntry,
  key: string,
  previous?: NormalizedBrandKitColor,
): BrandKitColorFileValue | BrandKitColorFileObject {
  const value = serializeColorValue(remote.value);
  const name = remote.name !== deriveColorName(key) ? remote.name : undefined;
  const displayFormat =
    previous?.explicitDisplayFormat !== undefined
      ? (remote.displayFormat ?? null)
      : undefined;
  if (name === undefined && displayFormat === undefined) {
    return value;
  }
  const entry: BrandKitColorFileObject = { value };
  if (name !== undefined) {
    entry.name = name;
  }
  if (displayFormat !== undefined) {
    entry.displayFormat = displayFormat;
  }
  return entry;
}

/**
 * Whether the local entry already represents the server color, so pull can
 * keep it verbatim. Only asserted fields count: a one-line entry never
 * differs by name or display format, so hand-written entries survive pulls
 * untouched while a UI rename still reaches entries that assert a name.
 */
function entriesEquivalent(
  local: NormalizedBrandKitColor,
  remote: BrandKitColorEntry,
): boolean {
  if (local.explicitName !== undefined && local.explicitName !== remote.name) {
    return false;
  }
  if (
    local.explicitDisplayFormat !== undefined &&
    (local.explicitDisplayFormat ?? null) !== (remote.displayFormat ?? null)
  ) {
    return false;
  }
  return (
    local.token !== null && colorTokenValuesEqual(local.token, remote.value)
  );
}

/**
 * Computes the pulled colors map: server colors in server order (palette
 * order), keeping the local entry — key spelling included — verbatim when
 * it is semantically equal, followed by local-only entries in their
 * original order. Pull never removes a color that exists only in the file;
 * entries with invalid keys are kept and reported rather than dropped. When
 * two entries name the same variable the first is kept and the rest are
 * reported as dropped duplicates. With `skipOverwrite`, existing local
 * entries are left untouched in place and only new server colors append.
 */
export function planColorPull(
  remoteColors: BrandKitColorEntry[],
  localColors: BrandKitColorsFileMap | undefined,
  options: ColorPullOptions = {},
): ColorPullPlan {
  const rawEntries = Object.entries(localColors ?? {});
  const normalized = normalizeBrandKitColors(localColors ?? {});
  const normalizedByRawKey = new Map(normalized.map((c) => [c.rawKey, c]));

  const localByVariable = new Map<string, NormalizedBrandKitColor>();
  const duplicates: string[] = [];
  for (const color of normalized) {
    if (localByVariable.has(color.cssVariable)) {
      duplicates.push(color.rawKey);
      continue;
    }
    localByVariable.set(color.cssVariable, color);
  }
  const remoteVariables = new Set(remoteColors.map((c) => c.cssVariable));

  const added: string[] = [];
  const updated: string[] = [];
  let unchanged = 0;
  const localOnly: string[] = [];
  const nextColors: BrandKitColorsFileMap = {};

  if (options.skipOverwrite) {
    for (const [rawKey, rawValue] of rawEntries) {
      nextColors[rawKey] = rawValue as BrandKitColorsFileMap[string];
    }
    let addedCount = 0;
    for (const remote of remoteColors) {
      if (localByVariable.has(remote.cssVariable)) {
        unchanged++;
        continue;
      }
      const key = remote.cssVariable.slice(2);
      nextColors[key] = toFileValue(remote, key);
      added.push(itemName(remote.name, remote.cssVariable));
      addedCount++;
    }
    for (const color of localByVariable.values()) {
      if (!remoteVariables.has(color.cssVariable)) {
        localOnly.push(itemName(color.name, color.cssVariable));
      }
    }
    return {
      colors: nextColors,
      added,
      updated,
      unchanged,
      localOnly,
      duplicates: [],
      changed: addedCount > 0,
    };
  }

  for (const remote of remoteColors) {
    const local = localByVariable.get(remote.cssVariable);
    const key = remote.cssVariable.slice(2);
    if (local === undefined) {
      nextColors[key] = toFileValue(remote, key);
      added.push(itemName(remote.name, remote.cssVariable));
      continue;
    }
    if (entriesEquivalent(local, remote)) {
      nextColors[local.rawKey] = local.rawValue;
      unchanged++;
      continue;
    }
    nextColors[local.rawKey] = toFileValue(remote, local.key, local);
    updated.push(itemName(remote.name, remote.cssVariable));
  }

  for (const [rawKey, rawValue] of rawEntries) {
    const color = normalizedByRawKey.get(rawKey);
    if (color === undefined) {
      // Invalid key: keep the entry and report it, never drop it silently.
      nextColors[rawKey] = rawValue as BrandKitColorsFileMap[string];
      localOnly.push(`"${rawKey}" (invalid color key, kept)`);
      continue;
    }
    if (localByVariable.get(color.cssVariable) !== color) {
      // A dropped duplicate, already reported.
      continue;
    }
    if (!remoteVariables.has(color.cssVariable)) {
      nextColors[rawKey] = rawValue as BrandKitColorsFileMap[string];
      localOnly.push(itemName(color.name, color.cssVariable));
    }
  }

  const nextEntries = Object.entries(nextColors);
  const changed =
    localColors === undefined
      ? remoteColors.length > 0
      : nextEntries.length !== rawEntries.length ||
        nextEntries.some(
          ([key, value], i) =>
            key !== rawEntries[i][0] || value !== rawEntries[i][1],
        );

  return {
    colors: nextColors,
    added,
    updated,
    unchanged,
    localOnly,
    duplicates,
    changed,
  };
}

/**
 * Reads the raw `colors` map from canvas.brand-kit.json, distinguishing an
 * absent key (colors not managed, undefined) from an authored empty map.
 * A missing or unreadable file also yields undefined.
 */
export async function readBrandKitColorsFile(
  projectRoot: string,
): Promise<BrandKitColorsFileMap | undefined> {
  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let parsed: unknown;
  try {
    parsed = JSON.parse(await fs.readFile(configPath, 'utf-8'));
  } catch {
    return undefined;
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return undefined;
  }
  const colors = (parsed as { colors?: unknown }).colors;
  if (colors && typeof colors === 'object' && !Array.isArray(colors)) {
    return colors as BrandKitColorsFileMap;
  }
  return undefined;
}

/**
 * Writes the colors map into canvas.brand-kit.json, preserving every other
 * top-level key. Creates the file when it does not exist.
 */
export async function writeBrandKitColorsConfig(
  projectRoot: string,
  colors: BrandKitColorsFileMap,
): Promise<void> {
  const configPath = path.resolve(projectRoot, BRAND_KIT_CONFIG_FILENAME);
  let fileContent: Record<string, unknown> = {};
  try {
    const raw = await fs.readFile(configPath, 'utf-8');
    const parsed = JSON.parse(raw);
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      fileContent = parsed as Record<string, unknown>;
    }
  } catch {
    // File missing or invalid; start with an empty object.
  }

  await fs.writeFile(
    configPath,
    `${JSON.stringify({ ...fileContent, colors }, null, 2)}\n`,
    'utf-8',
  );
}
