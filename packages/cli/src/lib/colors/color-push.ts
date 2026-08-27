import {
  colorTokenValuesEqual,
  normalizeColorValue,
} from '@drupal-canvas/discovery';

import { validateColorsConfig } from './color-validate.js';

import type {
  BrandKitColorFileEntry,
  ColorTokenValue,
} from '@drupal-canvas/discovery';
import type { ApiService } from '../../services/api.js';
import type {
  BrandKitColorEntry,
  BrandKitColorPayload,
} from '../../types/Component.js';
import type { Result } from '../../types/Result.js';

export type ColorPushOperation = 'create' | 'update' | 'unchanged' | 'delete';

export interface ColorPushOutcome {
  itemName: string;
  operation: ColorPushOperation;
  success: boolean;
  /** Failure reason (e.g. the server refusing to delete a color in use). */
  detail?: string;
}

export interface ColorPushResult {
  created: number;
  updated: number;
  unchanged: number;
  deleted: number;
  /** Names of colors on the server that are absent from the local file. */
  serverOnly: string[];
  outcomes: ColorPushOutcome[];
}

interface LocalColor {
  entry: BrandKitColorFileEntry;
  token: ColorTokenValue;
  index: number;
}

function itemName(name: string, cssVariable: string): string {
  return `${name} (${cssVariable})`;
}

/**
 * Full explicit value payload for the API. The server merges partial `value`
 * objects on update (and clears a stale hex), so always sending every key —
 * with explicit nulls — keeps the stored value exactly what the file says.
 */
function toValuePayload(token: ColorTokenValue): ColorTokenValue {
  return {
    colorSpace: token.colorSpace,
    components: token.components,
    alpha: token.alpha ?? null,
    hex: token.hex ?? null,
  };
}

function normalizeLocalColors(colors: BrandKitColorFileEntry[]): LocalColor[] {
  return colors.map((entry, index) => ({
    entry,
    // Validation guarantees the value parses.
    token: normalizeColorValue(entry.value) as ColorTokenValue,
    index,
  }));
}

function displayFormatsEqual(
  local: BrandKitColorFileEntry,
  remote: BrandKitColorEntry,
): boolean {
  return (local.displayFormat ?? null) === (remote.displayFormat ?? null);
}

/**
 * Whether pushing must rewrite weights to make the server's palette order
 * match the file's array order. Weights are left alone when the relative
 * order of the file's colors already matches their relative order on the
 * server and any new colors simply append — so a push right after a pull
 * writes nothing.
 */
function needsWeightReassignment(
  locals: LocalColor[],
  remote: BrandKitColorEntry[],
): boolean {
  const remoteVariables = new Set(remote.map((c) => c.cssVariable));
  const localMatchedOrder = locals
    .filter((c) => remoteVariables.has(c.entry.cssVariable))
    .map((c) => c.entry.cssVariable);
  const localVariables = new Set(locals.map((c) => c.entry.cssVariable));
  const remoteMatchedOrder = remote
    .filter((c) => localVariables.has(c.cssVariable))
    .map((c) => c.cssVariable);

  if (localMatchedOrder.length !== remoteMatchedOrder.length) {
    // Unreachable when both sides deduplicate variables; be conservative.
    return true;
  }
  for (let i = 0; i < localMatchedOrder.length; i++) {
    if (localMatchedOrder[i] !== remoteMatchedOrder[i]) {
      return true;
    }
  }

  // New colors inserted anywhere except after every existing one need
  // explicit weights to land where the file puts them.
  const lastMatchedIndex = locals.reduce(
    (max, c) =>
      remoteVariables.has(c.entry.cssVariable) ? Math.max(max, c.index) : max,
    -1,
  );
  return locals.some(
    (c) =>
      !remoteVariables.has(c.entry.cssVariable) && c.index < lastMatchedIndex,
  );
}

/**
 * Builds planned color rows for the push plan (local entries vs the remote
 * brand kit). Colors on the server but absent locally are planned for
 * deletion only when pruning is requested; otherwise they are left alone
 * (and reported during the push).
 */
export function buildColorPushPlannedResults(
  colors: BrandKitColorFileEntry[],
  remoteColors: BrandKitColorEntry[],
  operationLabels: { create: string; update: string; delete: string },
  pruneColors: boolean,
): Result[] {
  const remoteByVariable = new Map(remoteColors.map((c) => [c.cssVariable, c]));
  const localVariables = new Set(colors.map((c) => c.cssVariable));
  const results: Result[] = [];

  for (const entry of colors) {
    results.push({
      itemName: itemName(entry.name, entry.cssVariable),
      itemType: 'Color',
      success: true,
      details: [
        {
          content: remoteByVariable.has(entry.cssVariable)
            ? operationLabels.update
            : operationLabels.create,
        },
      ],
    });
  }

  if (pruneColors) {
    for (const remote of remoteColors) {
      if (!localVariables.has(remote.cssVariable)) {
        results.push({
          itemName: itemName(remote.name, remote.cssVariable),
          itemType: 'Color',
          success: true,
          details: [{ content: operationLabels.delete }],
        });
      }
    }
  }

  return results;
}

/**
 * Push colors from canvas.brand-kit.json to the site via the color config
 * endpoints. Matches entries to server colors by `cssVariable`, creates and
 * updates as needed, and never deletes unless `pruneColors` is set — a color
 * present only on the server is reported instead. Returns null when the file
 * has no `colors` key (colors are not managed by this project).
 */
export async function pushColors(
  colors: BrandKitColorFileEntry[] | undefined,
  api: ApiService,
  options: { pruneColors?: boolean } = {},
): Promise<ColorPushResult | null> {
  if (colors === undefined) {
    return null;
  }
  validateColorsConfig(colors);

  const brandKit = await api.getBrandKit();
  const remote = brandKit.colors ?? [];
  const remoteByVariable = new Map(remote.map((c) => [c.cssVariable, c]));
  const localVariables = new Set(colors.map((c) => c.cssVariable));
  const locals = normalizeLocalColors(colors);

  const reassignWeights = needsWeightReassignment(locals, remote);
  const maxRemoteWeight = remote.reduce(
    (max, c) => Math.max(max, c.weight),
    -1,
  );

  const outcomes: ColorPushOutcome[] = [];
  let created = 0;
  let updated = 0;
  let unchanged = 0;
  let deleted = 0;
  let createSequence = 0;

  for (const local of locals) {
    const name = itemName(local.entry.name, local.entry.cssVariable);
    const existing = remoteByVariable.get(local.entry.cssVariable);

    if (!existing) {
      const payload: BrandKitColorPayload = {
        name: local.entry.name,
        cssVariable: local.entry.cssVariable,
        value: toValuePayload(local.token),
        weight: reassignWeights
          ? local.index
          : maxRemoteWeight + 1 + createSequence,
      };
      if (local.entry.displayFormat != null) {
        payload.displayFormat = local.entry.displayFormat;
      }
      createSequence++;
      await api.createColor(payload);
      created++;
      outcomes.push({ itemName: name, operation: 'create', success: true });
      continue;
    }

    const changes: Partial<BrandKitColorPayload> = {};
    if (local.entry.name !== existing.name) {
      changes.name = local.entry.name;
    }
    if (!colorTokenValuesEqual(local.token, existing.value)) {
      changes.value = toValuePayload(local.token);
    }
    if (!displayFormatsEqual(local.entry, existing)) {
      changes.displayFormat = local.entry.displayFormat ?? null;
    }
    if (reassignWeights && existing.weight !== local.index) {
      changes.weight = local.index;
    }

    if (Object.keys(changes).length === 0) {
      unchanged++;
      outcomes.push({ itemName: name, operation: 'unchanged', success: true });
      continue;
    }
    await api.updateColor(existing.id, changes);
    updated++;
    outcomes.push({ itemName: name, operation: 'update', success: true });
  }

  const serverOnly = remote.filter((c) => !localVariables.has(c.cssVariable));
  const serverOnlyNames: string[] = [];
  for (const color of serverOnly) {
    const name = itemName(color.name, color.cssVariable);
    if (!options.pruneColors) {
      serverOnlyNames.push(name);
      continue;
    }
    try {
      await api.deleteColor(color.id);
      deleted++;
      outcomes.push({ itemName: name, operation: 'delete', success: true });
    } catch (error) {
      // A color in use cannot be deleted; report the server's reason and
      // keep pushing the rest.
      outcomes.push({
        itemName: name,
        operation: 'delete',
        success: false,
        detail: error instanceof Error ? error.message : String(error),
      });
    }
  }

  return {
    created,
    updated,
    unchanged,
    deleted,
    serverOnly: serverOnlyNames,
    outcomes,
  };
}
