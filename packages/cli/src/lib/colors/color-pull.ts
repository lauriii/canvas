import fs from 'fs/promises';
import path from 'path';
import {
  BRAND_KIT_CONFIG_FILENAME,
  colorTokenValuesEqual,
  normalizeColorValue,
  serializeColorValue,
} from '@drupal-canvas/discovery';

import type { BrandKitColorFileEntry } from '@drupal-canvas/discovery';
import type { BrandKitColorEntry } from '../../types/Component.js';

export interface ColorPullPlan {
  /** The colors array the file should contain after the pull. */
  colors: BrandKitColorFileEntry[];
  /** Item names of colors newly added to the file. */
  added: string[];
  /** Item names of colors whose file entry was updated from the server. */
  updated: string[];
  /** Count of colors already represented in the file and left untouched. */
  unchanged: number;
  /** Item names of file entries with no matching color on the server. */
  localOnly: string[];
  /** Item names of dropped duplicate entries (same cssVariable, first kept). */
  duplicates: string[];
  /** Whether the file's colors array needs to be written at all. */
  changed: boolean;
}

export interface ColorPullOptions {
  /** Keep existing local entries untouched; only append new server colors. */
  skipOverwrite?: boolean;
}

function itemName(
  name: string | undefined,
  cssVariable: string | undefined,
): string {
  return `${name ?? '(unnamed)'} (${cssVariable ?? 'no cssVariable'})`;
}

/**
 * Serializes a server color into a hand-editable file entry: fixed key
 * order, hex string when lossless, `displayFormat` only when set.
 */
function toFileEntry(remote: BrandKitColorEntry): BrandKitColorFileEntry {
  const entry: BrandKitColorFileEntry = {
    name: remote.name,
    cssVariable: remote.cssVariable,
    value: serializeColorValue(remote.value),
  };
  if (remote.displayFormat != null) {
    entry.displayFormat = remote.displayFormat;
  }
  return entry;
}

function entriesEquivalent(
  local: BrandKitColorFileEntry,
  remote: BrandKitColorEntry,
): boolean {
  if (local.name !== remote.name) {
    return false;
  }
  if ((local.displayFormat ?? null) !== (remote.displayFormat ?? null)) {
    return false;
  }
  const token = normalizeColorValue(local.value);
  return token !== null && colorTokenValuesEqual(token, remote.value);
}

/**
 * Computes the pulled colors array: server colors in server order (palette
 * order), keeping the local entry verbatim when it is semantically equal so
 * hand formatting survives, followed by local-only entries in their original
 * order — pull never removes a color that exists only in the file. When two
 * local entries share a cssVariable the first is kept and the rest are
 * reported as dropped duplicates. With `skipOverwrite`, existing local
 * entries are left untouched in place and only new server colors append.
 */
export function planColorPull(
  remoteColors: BrandKitColorEntry[],
  localColors: BrandKitColorFileEntry[] | undefined,
  options: ColorPullOptions = {},
): ColorPullPlan {
  const locals = localColors ?? [];
  const localByVariable = new Map<string, BrandKitColorFileEntry>();
  const duplicates: string[] = [];
  for (const local of locals) {
    if (localByVariable.has(local?.cssVariable as string)) {
      duplicates.push(itemName(local?.name, local?.cssVariable));
      continue;
    }
    localByVariable.set(local?.cssVariable as string, local);
  }
  const remoteVariables = new Set(remoteColors.map((c) => c.cssVariable));

  const added: string[] = [];
  const updated: string[] = [];
  let unchanged = 0;

  if (options.skipOverwrite) {
    const newRemote = remoteColors.filter(
      (remote) => !localByVariable.has(remote.cssVariable),
    );
    for (const remote of newRemote) {
      added.push(itemName(remote.name, remote.cssVariable));
    }
    unchanged = remoteColors.length - newRemote.length;
    const localOnly = locals
      .filter((local) => !remoteVariables.has(local?.cssVariable as string))
      .map((local) => itemName(local?.name, local?.cssVariable));
    return {
      colors: [...locals, ...newRemote.map(toFileEntry)],
      added,
      updated,
      unchanged,
      localOnly,
      duplicates: [],
      changed: newRemote.length > 0,
    };
  }

  const nextColors: BrandKitColorFileEntry[] = remoteColors.map((remote) => {
    const local = localByVariable.get(remote.cssVariable);
    if (local === undefined) {
      added.push(itemName(remote.name, remote.cssVariable));
      return toFileEntry(remote);
    }
    if (entriesEquivalent(local, remote)) {
      unchanged++;
      return local;
    }
    updated.push(itemName(remote.name, remote.cssVariable));
    return toFileEntry(remote);
  });

  const localOnly: string[] = [];
  for (const local of localByVariable.values()) {
    if (!remoteVariables.has(local?.cssVariable as string)) {
      localOnly.push(itemName(local?.name, local?.cssVariable));
      nextColors.push(local);
    }
  }

  const changed =
    localColors === undefined
      ? remoteColors.length > 0
      : nextColors.length !== locals.length ||
        nextColors.some((entry, i) => entry !== locals[i]);

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
 * Writes the colors array into canvas.brand-kit.json, preserving every other
 * top-level key. Creates the file when it does not exist.
 */
export async function writeBrandKitColorsConfig(
  projectRoot: string,
  colors: BrandKitColorFileEntry[],
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
