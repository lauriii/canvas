import {
  colorTokenValuesEqual,
  normalizeBrandKitColors,
} from '@drupal-canvas/discovery';

import { validateColorsConfig } from './color-validate.js';

import type {
  BrandKitColorsFileMap,
  ColorTokenValue,
  NormalizedBrandKitColor,
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

/**
 * The display name a local entry asserts, if any. An entry without an
 * explicit name never renames an existing server color — the derived name
 * is only a default for newly created colors — so a UI-given label
 * survives hand-written one-line entries.
 */
function assertedName(color: NormalizedBrandKitColor): string | undefined {
  return color.explicitName;
}

/**
 * Whether pushing must rewrite weights to make the server's palette order
 * match the file's map order. Weights are left alone when the relative
 * order of the file's colors already matches their relative order on the
 * server and any new colors simply append — so a push right after a pull
 * writes nothing.
 */
function needsWeightReassignment(
  locals: NormalizedBrandKitColor[],
  remote: BrandKitColorEntry[],
): boolean {
  const remoteVariables = new Set(remote.map((c) => c.cssVariable));
  const localMatchedOrder = locals
    .filter((c) => remoteVariables.has(c.cssVariable))
    .map((c) => c.cssVariable);
  const localVariables = new Set(locals.map((c) => c.cssVariable));
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
    (max, c, index) =>
      remoteVariables.has(c.cssVariable) ? Math.max(max, index) : max,
    -1,
  );
  return locals.some(
    (c, index) =>
      !remoteVariables.has(c.cssVariable) && index < lastMatchedIndex,
  );
}

/**
 * Builds planned color rows for the push plan (local entries vs the remote
 * brand kit). Colors on the server but absent locally are planned for
 * deletion only when pruning is requested; otherwise they are left alone
 * (and reported during the push).
 */
export function buildColorPushPlannedResults(
  colors: BrandKitColorsFileMap,
  remoteColors: BrandKitColorEntry[],
  operationLabels: { create: string; update: string; delete: string },
  pruneColors: boolean,
): Result[] {
  const locals = normalizeBrandKitColors(colors);
  const remoteByVariable = new Map(remoteColors.map((c) => [c.cssVariable, c]));
  const localVariables = new Set(locals.map((c) => c.cssVariable));
  const results: Result[] = [];

  for (const color of locals) {
    const existing = remoteByVariable.get(color.cssVariable);
    results.push({
      itemName: itemName(
        assertedName(color) ?? existing?.name ?? color.name,
        color.cssVariable,
      ),
      itemType: 'Color',
      success: true,
      details: [
        {
          content: existing ? operationLabels.update : operationLabels.create,
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
 * endpoints. Matches map keys to server colors by CSS variable, creates and
 * updates as needed, and never deletes unless `pruneColors` is set — a color
 * present only on the server is reported instead. Returns null when the file
 * has no `colors` key (colors are not managed by this project).
 */
export async function pushColors(
  colors: BrandKitColorsFileMap | undefined,
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
  // Validation guarantees unique keys and parseable values.
  const locals = normalizeBrandKitColors(colors);
  const localVariables = new Set(locals.map((c) => c.cssVariable));

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

  // Prune first so a variable that moved from a server-only color to a new
  // file entry frees its unique cssVariable before the create runs.
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

  // The site requires unique display names, so check every name that will
  // exist after this push — intended local names plus names retained on the
  // server (kept server-only colors and refused prune deletions) — before
  // any create or update, so a collision is one named error rather than a
  // server rejection after earlier mutations.
  {
    const finalNames = new Map<string, string>();
    const collisions: string[] = [];
    const claim = (rawName: string, label: string) => {
      const normalized = rawName.trim().toLowerCase();
      if (normalized === '') {
        return;
      }
      const holder = finalNames.get(normalized);
      if (holder !== undefined) {
        collisions.push(`"${rawName}" is used by both ${holder} and ${label}.`);
        return;
      }
      finalNames.set(normalized, label);
    };
    for (const local of locals) {
      const existing = remoteByVariable.get(local.cssVariable);
      const finalName = assertedName(local) ?? existing?.name ?? local.name;
      claim(finalName, itemName(finalName, local.cssVariable));
    }
    const refusedPruneVariables = new Set(
      outcomes
        .filter((o) => o.operation === 'delete' && !o.success)
        .map((o) => o.itemName),
    );
    for (const color of serverOnly) {
      const kept =
        !options.pruneColors ||
        refusedPruneVariables.has(itemName(color.name, color.cssVariable));
      if (kept) {
        claim(
          color.name,
          `${itemName(color.name, color.cssVariable)} (on the site, not in the file)`,
        );
      }
    }
    if (collisions.length > 0) {
      throw new Error(
        `Color name conflicts (the site requires unique color names, compared case-insensitively):\n${collisions.join('\n')}`,
      );
    }
  }

  for (let index = 0; index < locals.length; index++) {
    const local = locals[index];
    const token = local.token as ColorTokenValue;
    const existing = remoteByVariable.get(local.cssVariable);
    const name = itemName(
      assertedName(local) ?? existing?.name ?? local.name,
      local.cssVariable,
    );

    if (!existing) {
      const payload: BrandKitColorPayload = {
        name: local.name,
        cssVariable: local.cssVariable,
        value: toValuePayload(token),
        weight: reassignWeights ? index : maxRemoteWeight + 1 + createSequence,
      };
      // The display format defaults from the value's string form ("#..."
      // edits as hex, "hsl(...)" as HSL) unless the file asserts one — and
      // an asserted null means the server default, not the derived format.
      const displayFormat =
        local.explicitDisplayFormat !== undefined
          ? local.explicitDisplayFormat
          : local.derivedDisplayFormat;
      if (displayFormat != null) {
        payload.displayFormat = displayFormat;
      }
      createSequence++;
      await api.createColor(payload);
      created++;
      outcomes.push({ itemName: name, operation: 'create', success: true });
      continue;
    }

    const changes: Partial<BrandKitColorPayload> = {};
    const localName = assertedName(local);
    if (localName !== undefined && localName !== existing.name) {
      changes.name = localName;
    }
    if (!colorTokenValuesEqual(token, existing.value)) {
      changes.value = toValuePayload(token);
    }
    // Only an explicitly asserted display format updates the server; the
    // derived one is a creation default, never a diff.
    if (
      local.explicitDisplayFormat !== undefined &&
      (local.explicitDisplayFormat ?? null) !== (existing.displayFormat ?? null)
    ) {
      changes.displayFormat = local.explicitDisplayFormat ?? null;
    }
    if (reassignWeights && existing.weight !== index) {
      changes.weight = index;
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

  return {
    created,
    updated,
    unchanged,
    deleted,
    serverOnly: serverOnlyNames,
    outcomes,
  };
}
