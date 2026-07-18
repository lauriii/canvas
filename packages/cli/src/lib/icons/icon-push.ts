import fs from 'fs/promises';
import path from 'path';

import { ICONS_DIR, validateIconLibraryDir } from './icon-validate.js';

import type { ApiService } from '../../services/api.js';
import type {
  IconLibrary,
  IconLibraryAssetInput,
} from '../../types/IconLibrary.js';
import type { Result } from '../../types/Result.js';
import type { PushOperationResult } from '../../utils/push-resource-pipeline.js';
import type { ValidatedIconLibrary } from './icon-validate.js';

export interface DiscoveredIconLibrary {
  id: string;
  dir: string;
}

export type IconPushOperation = 'create' | 'update' | 'unchanged';

export interface IconLibraryPushOutcome {
  id: string;
  operation?: IconPushOperation;
  success: boolean;
  /** Per-file upload failures or the create/update error. */
  errors: string[];
}

export interface IconLibraryPreparationFailure {
  index: number;
  id: string;
  error: Error;
}

export interface IconPushResult {
  outcomes: IconLibraryPushOutcome[];
  created: number;
  updated: number;
  unchanged: number;
  failed: number;
}

function formatErrorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

/**
 * Discovers local icon libraries: directories under icons/ that contain a
 * manifest.json. Directories without one (e.g. module-provided pack.json
 * directories written by pull) are skipped.
 */
export async function discoverIconLibraryDirs(
  projectRoot: string,
): Promise<DiscoveredIconLibrary[]> {
  const iconsDir = path.resolve(projectRoot, ICONS_DIR);
  let entries;
  try {
    entries = await fs.readdir(iconsDir, { withFileTypes: true });
  } catch (err) {
    // The icons directory is optional.
    if (
      err &&
      typeof err === 'object' &&
      'code' in err &&
      err.code === 'ENOENT'
    ) {
      return [];
    }
    throw err;
  }

  const libraries: DiscoveredIconLibrary[] = [];
  const directories = entries
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort((a, b) => a.localeCompare(b));
  for (const name of directories) {
    const dir = path.join(iconsDir, name);
    const hasManifest = await fs
      .access(path.join(dir, 'manifest.json'))
      .then(() => true)
      .catch(() => false);
    if (!hasManifest) {
      continue;
    }
    libraries.push({ id: name, dir });
  }
  return libraries;
}

/**
 * Discovers and validates local icon libraries for push. Validation failures
 * fail only that library; other libraries continue.
 */
export async function prepareIconLibrariesPush(projectRoot: string): Promise<{
  valid: Array<{ index: number; result: ValidatedIconLibrary }>;
  failed: IconLibraryPreparationFailure[];
}> {
  const discovered = await discoverIconLibraryDirs(projectRoot);
  const valid: Array<{ index: number; result: ValidatedIconLibrary }> = [];
  const failed: IconLibraryPreparationFailure[] = [];

  for (const [index, library] of discovered.entries()) {
    try {
      valid.push({ index, result: await validateIconLibraryDir(library.dir) });
    } catch (error) {
      failed.push({
        index,
        id: library.id,
        error: error instanceof Error ? error : new Error(String(error)),
      });
    }
  }

  return { valid, failed };
}

/**
 * Returns true when the local manifest fields and uploaded assets match the
 * remote library, so the create/update request can be skipped.
 */
function iconLibraryUnchanged(
  library: ValidatedIconLibrary,
  assets: IconLibraryAssetInput[],
  existing: IconLibrary,
): boolean {
  const manifest = library.manifest;
  if (manifest.label !== existing.label) return false;
  if ((manifest.description ?? null) !== (existing.description ?? null)) {
    return false;
  }
  if ((manifest.template ?? null) !== (existing.template ?? null)) {
    return false;
  }
  const remoteAssets = existing.assets ?? [];
  if (assets.length !== remoteAssets.length) return false;
  const remoteUriByName = new Map(
    remoteAssets.map((asset) => [asset.name, asset.uri]),
  );
  for (const asset of assets) {
    if (remoteUriByName.get(asset.name) !== asset.uri) return false;
  }
  return true;
}

/**
 * Pushes one validated icon library.
 *
 * The asset upload route resolves the icon_library entity, so a new library
 * must be created (with no assets yet) before its SVGs can be uploaded. The
 * order is therefore: create the entity if missing, upload each SVG, then
 * update the entity with the full assets list. A failed file fails the
 * library (no final update is sent); errors carry the file path and the
 * server's error strings.
 */
export async function pushIconLibrary(
  api: Pick<
    ApiService,
    'uploadIconAsset' | 'createIconLibrary' | 'updateIconLibrary'
  >,
  library: ValidatedIconLibrary,
  existing: IconLibrary | undefined,
): Promise<IconLibraryPushOutcome> {
  const manifest = library.manifest;
  const isNew = !existing;

  if (isNew) {
    try {
      await api.createIconLibrary({
        id: library.id,
        label: manifest.label,
        ...(manifest.description !== undefined && {
          description: manifest.description,
        }),
        ...(manifest.template !== undefined && {
          template: manifest.template,
        }),
        assets: null,
      });
    } catch (error) {
      return {
        id: library.id,
        success: false,
        errors: [formatErrorMessage(error)],
      };
    }
  }

  const assets: IconLibraryAssetInput[] = [];
  const errors: string[] = [];

  for (const filename of library.svgFiles) {
    try {
      const buffer = await fs.readFile(path.join(library.dir, filename));
      const uploaded = await api.uploadIconAsset(library.id, filename, buffer);
      assets.push({ name: filename, uri: uploaded.uri });
    } catch (error) {
      errors.push(
        `${path.join(ICONS_DIR, library.id, filename)}: ${formatErrorMessage(error)}`,
      );
    }
  }

  if (errors.length > 0) {
    return { id: library.id, success: false, errors };
  }

  try {
    if (!isNew && iconLibraryUnchanged(library, assets, existing)) {
      return {
        id: library.id,
        operation: 'unchanged',
        success: true,
        errors: [],
      };
    }

    await api.updateIconLibrary(library.id, {
      label: manifest.label,
      description: manifest.description ?? null,
      template: manifest.template ?? null,
      assets,
    });
    return {
      id: library.id,
      operation: isNew ? 'create' : 'update',
      success: true,
      errors: [],
    };
  } catch (error) {
    return {
      id: library.id,
      success: false,
      errors: [formatErrorMessage(error)],
    };
  }
}

/**
 * Push all local icon libraries (each icons/ directory with a manifest.json)
 * to the server.
 * Validation and push failures fail only that library; other libraries
 * continue. Local validation runs before any network request.
 */
export async function pushIcons(
  api: ApiService,
  projectRoot: string,
): Promise<IconPushResult> {
  const { valid, failed } = await prepareIconLibrariesPush(projectRoot);

  const outcomeByIndex = new Map<number, IconLibraryPushOutcome>();
  for (const failure of failed) {
    outcomeByIndex.set(failure.index, {
      id: failure.id,
      success: false,
      errors: [failure.error.message],
    });
  }

  if (valid.length > 0) {
    const remote = await api.getIconLibraries();
    for (const entry of valid) {
      outcomeByIndex.set(
        entry.index,
        await pushIconLibrary(api, entry.result, remote[entry.result.id]),
      );
    }
  }

  const outcomes = [...outcomeByIndex.entries()]
    .sort(([a], [b]) => a - b)
    .map(([, outcome]) => outcome);

  return {
    outcomes,
    created: outcomes.filter((o) => o.operation === 'create').length,
    updated: outcomes.filter((o) => o.operation === 'update').length,
    unchanged: outcomes.filter((o) => o.operation === 'unchanged').length,
    failed: outcomes.filter((o) => !o.success).length,
  };
}

const OPERATION_RESULT_LABELS: Record<IconPushOperation, string> = {
  create: 'Created',
  update: 'Updated',
  unchanged: 'Unchanged',
};

/**
 * Collects icon library push results into Result[] for reporting.
 */
export function collectIconLibraryResults(
  pushResults: Array<PushOperationResult<IconLibraryPushOutcome>>,
  failedPreps: IconLibraryPreparationFailure[],
  discovered: DiscoveredIconLibrary[],
): Result[] {
  const results: Result[] = [];

  for (const result of pushResults) {
    const outcome = result.result;
    const itemName = outcome?.id ?? discovered[result.index]?.id ?? 'unknown';
    if (result.success && outcome?.operation) {
      results.push({
        itemName,
        success: true,
        details: [{ content: OPERATION_RESULT_LABELS[outcome.operation] }],
      });
    } else {
      const errors = outcome?.errors.length
        ? outcome.errors
        : [result.error?.message || 'Unknown error'];
      results.push({
        itemName,
        success: false,
        details: errors.map((content) => ({ content })),
      });
    }
  }

  for (const failedPrep of failedPreps) {
    results.push({
      itemName: failedPrep.id,
      success: false,
      details: [
        {
          content:
            failedPrep.error?.message || 'Failed to validate icon library',
        },
      ],
    });
  }

  return results;
}

/**
 * Builds planned icon library rows for the push plan (local libraries vs
 * remote icon_library config entities).
 */
export function buildIconPushPlannedResults(
  localLibraryIds: string[],
  remoteLibraries: Record<string, IconLibrary>,
  operationLabels: { create: string; update: string },
): Result[] {
  return localLibraryIds.map((id) => ({
    itemName: id,
    itemType: 'Icon library',
    success: true,
    details: [
      {
        content:
          id in remoteLibraries
            ? operationLabels.update
            : operationLabels.create,
      },
    ],
  }));
}
