import crypto from 'crypto';
import fs from 'fs/promises';
import path from 'path';

import { processInPool } from '../../utils/request-pool.js';
import { readBrandKitIconsConfig } from './icon-config.js';
import { ICONS_DIR, validateIconLibraryEntry } from './icon-validate.js';

import type { IconLibraryEntry } from '../../config.js';
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
  entry: IconLibraryEntry;
}

export type IconPushOperation = 'create' | 'update' | 'unchanged' | 'delete';

export interface IconLibraryPushOutcome {
  id: string;
  operation?: IconPushOperation;
  success: boolean;
  /** Files actually uploaded (new or changed content). */
  uploadedCount?: number;
  /** Files skipped because the server already has identical content. */
  skippedCount?: number;
  /** Per-file upload failures or the create/update error. */
  errors: string[];
}

export interface IconPushOptions {
  /** Concurrent uploads per library. */
  concurrency?: number;
  /** Per-library upload progress: processed files out of the total. */
  onProgress?: (libraryId: string, done: number, total: number) => void;
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
  deleted: number;
  failed: number;
}

function formatErrorMessage(error: unknown): string {
  return error instanceof Error ? error.message : String(error);
}

/**
 * Discovers local icon libraries, mirroring the fonts workflow: every library
 * is explicitly declared in canvas.brand-kit.json (`icons.libraries`) with at
 * least a human-readable `label`. Nothing is inferred from bare `icons/<id>/`
 * directories.
 *
 * When the `icons` key is present in canvas.brand-kit.json, the resulting
 * set is authoritative: push removes remote canvas-managed libraries that
 * are not in it, the same replace semantics fonts use.
 */
export async function discoverIconLibraries(projectRoot: string): Promise<{
  libraries: DiscoveredIconLibrary[];
  /** True when canvas.brand-kit.json declares an `icons` key. */
  authoritative: boolean;
}> {
  const iconsConfig = await readBrandKitIconsConfig(projectRoot);
  const libraries: DiscoveredIconLibrary[] = (iconsConfig?.libraries ?? []).map(
    (entry) => ({ id: entry.id, entry }),
  );
  return { libraries, authoritative: iconsConfig !== undefined };
}

/**
 * Discovers and validates local icon libraries for push. Validation failures
 * fail only that library; other libraries continue.
 */
export async function prepareIconLibrariesPush(projectRoot: string): Promise<{
  valid: Array<{ index: number; result: ValidatedIconLibrary }>;
  failed: IconLibraryPreparationFailure[];
  /** True when canvas.brand-kit.json declares an `icons` key. */
  authoritative: boolean;
  /** Every locally known library id, valid or not. */
  localIds: string[];
}> {
  const { libraries, authoritative } = await discoverIconLibraries(projectRoot);
  const valid: Array<{ index: number; result: ValidatedIconLibrary }> = [];
  const failed: IconLibraryPreparationFailure[] = [];

  for (const [index, library] of libraries.entries()) {
    try {
      valid.push({
        index,
        result: await validateIconLibraryEntry(library.entry, projectRoot),
      });
    } catch (error) {
      failed.push({
        index,
        id: library.id,
        error: error instanceof Error ? error : new Error(String(error)),
      });
    }
  }

  return {
    valid,
    failed,
    authoritative,
    localIds: libraries.map((library) => library.id),
  };
}

/**
 * Remote canvas-managed libraries to delete: those missing from the local
 * set, when the local set is authoritative (an `icons` key exists in
 * canvas.brand-kit.json). Mirrors fonts' replace-the-remote-set semantics.
 */
export function planIconLibraryDeletions(
  remote: Record<string, IconLibrary>,
  localIds: string[],
  authoritative: boolean,
): string[] {
  if (!authoritative) {
    return [];
  }
  const localSet = new Set(localIds);
  return Object.keys(remote)
    .filter((id) => !localSet.has(id))
    .sort((a, b) => a.localeCompare(b));
}

/**
 * Returns true when the icon push pipeline's push step has work to do: valid
 * local libraries to push, or pending authoritative deletions of remote
 * canvas-managed libraries. An explicitly empty declared list carries no
 * valid libraries but must still reach the push step so its delete-all
 * semantics run.
 */
export function hasIconLibraryPushWork(
  validCount: number,
  remote: Record<string, IconLibrary>,
  localIds: string[],
  authoritative: boolean,
): boolean {
  return (
    validCount > 0 ||
    planIconLibraryDeletions(remote, localIds, authoritative).length > 0
  );
}

/**
 * Returns true when the local library fields and uploaded assets match the
 * remote library, so the create/update request can be skipped.
 */
function iconLibraryUnchanged(
  library: ValidatedIconLibrary,
  assets: IconLibraryAssetInput[],
  existing: IconLibrary,
): boolean {
  if (library.label !== existing.label) return false;
  if ((library.description ?? null) !== (existing.description ?? null)) {
    return false;
  }
  if ((library.template ?? null) !== (existing.template ?? null)) {
    return false;
  }
  const remoteAssets = existing.assets ?? [];
  if (assets.length !== remoteAssets.length) return false;
  const remoteByName = new Map(
    remoteAssets.map((asset) => [asset.name, asset]),
  );
  for (const asset of assets) {
    const remote = remoteByName.get(asset.name);
    if (remote === undefined || remote.uri !== asset.uri) return false;
    // A hash difference means changed content even at a stable URI. Entities
    // saved before hashes existed have none; treat those as changed so the
    // hash gets stored.
    if ((remote.hash ?? null) !== (asset.hash ?? null)) return false;
  }
  return true;
}

/**
 * Pushes one validated icon library.
 *
 * The asset upload route resolves the icon_library entity, so a new library
 * must be created (with no assets yet) before its SVGs can be uploaded. The
 * order is therefore: create the entity if missing, upload files, then update
 * the entity with the full assets list. Files whose SHA-256 matches the
 * remote asset entry are not re-uploaded, so incremental pushes only transfer
 * what changed, and uploads run concurrently. A failed file fails the library
 * (no final update is sent); errors carry the file path and the server's
 * error strings.
 */
export async function pushIconLibrary(
  api: Pick<
    ApiService,
    'uploadIconAsset' | 'createIconLibrary' | 'updateIconLibrary'
  >,
  library: ValidatedIconLibrary,
  existing: IconLibrary | undefined,
  options: IconPushOptions = {},
): Promise<IconLibraryPushOutcome> {
  const isNew = !existing;
  const concurrency = options.concurrency ?? 8;

  if (isNew) {
    try {
      await api.createIconLibrary({
        id: library.id,
        label: library.label,
        ...(library.description !== undefined && {
          description: library.description,
        }),
        ...(library.template !== undefined && {
          template: library.template,
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

  const remoteByName = new Map(
    (existing?.assets ?? []).map((asset) => [asset.name, asset]),
  );
  const total = library.svgFiles.length;
  let done = 0;
  const reportProgress = () => {
    done++;
    options.onProgress?.(library.id, done, total);
  };

  // Hash locally first so unchanged files never leave the machine.
  const planned: Array<{
    filename: string;
    hash: string;
    remoteUri?: string;
  }> = [];
  for (const filename of library.svgFiles) {
    const buffer = await fs.readFile(path.join(library.filesDir, filename));
    const hash = crypto.createHash('sha256').update(buffer).digest('hex');
    const remote = remoteByName.get(filename);
    planned.push({
      filename,
      hash,
      ...(remote?.hash === hash && { remoteUri: remote.uri }),
    });
  }

  const assetByName = new Map<string, IconLibraryAssetInput>();
  const errors: string[] = [];
  const toUpload = planned.filter((entry) => entry.remoteUri === undefined);
  for (const entry of planned) {
    if (entry.remoteUri !== undefined) {
      assetByName.set(entry.filename, {
        name: entry.filename,
        uri: entry.remoteUri,
        hash: entry.hash,
      });
      reportProgress();
    }
  }

  const uploadResults = await processInPool(
    toUpload,
    async (entry) => {
      const buffer = await fs.readFile(
        path.join(library.filesDir, entry.filename),
      );
      const uploaded = await api.uploadIconAsset(
        library.id,
        entry.filename,
        buffer,
      );
      reportProgress();
      return {
        name: entry.filename,
        uri: uploaded.uri,
        hash: uploaded.hash ?? entry.hash,
      };
    },
    concurrency,
  );
  for (const result of uploadResults) {
    if (result.success && result.result) {
      assetByName.set(result.result.name, result.result);
    } else {
      errors.push(
        `${path.join(ICONS_DIR, library.id, toUpload[result.index].filename)}: ${formatErrorMessage(result.error)}`,
      );
    }
  }

  if (errors.length > 0) {
    // An upload replaces the library's live file in place, so uploads that
    // succeeded before the failure are already serving their new artwork
    // while the library keeps the previous asset list. Report that rather
    // than leaving it silent; re-running the push re-uploads by hash and
    // reconciles the library.
    const uploaded = uploadResults.filter((result) => result.success).length;
    if (uploaded > 0) {
      errors.push(
        `${uploaded} of ${toUpload.length} assets were uploaded before the failure and are already live on the site; re-run the push to reconcile ${library.id}.`,
      );
    }
    return { id: library.id, success: false, errors };
  }

  // Preserve the validated (sorted) file order in the assets list.
  const assets = library.svgFiles.map((filename) => {
    const asset = assetByName.get(filename);
    if (asset === undefined) {
      throw new Error(`Missing upload result for ${filename}`);
    }
    return asset;
  });
  const uploadedCount = toUpload.length;
  const skippedCount = total - uploadedCount;

  try {
    if (!isNew && iconLibraryUnchanged(library, assets, existing)) {
      return {
        id: library.id,
        operation: 'unchanged',
        success: true,
        uploadedCount,
        skippedCount,
        errors: [],
      };
    }

    await api.updateIconLibrary(library.id, {
      label: library.label,
      description: library.description ?? null,
      template: library.template ?? null,
      assets,
    });
    return {
      id: library.id,
      operation: isNew ? 'create' : 'update',
      success: true,
      uploadedCount,
      skippedCount,
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
  options: IconPushOptions = {},
): Promise<IconPushResult> {
  const { valid, failed, authoritative, localIds } =
    await prepareIconLibrariesPush(projectRoot);

  const outcomeByIndex = new Map<number, IconLibraryPushOutcome>();
  for (const failure of failed) {
    outcomeByIndex.set(failure.index, {
      id: failure.id,
      success: false,
      errors: [failure.error.message],
    });
  }

  const deletions: IconLibraryPushOutcome[] = [];
  if (valid.length > 0 || authoritative) {
    const remote = await api.getIconLibraries();
    for (const entry of valid) {
      outcomeByIndex.set(
        entry.index,
        await pushIconLibrary(
          api,
          entry.result,
          remote[entry.result.id],
          options,
        ),
      );
    }

    // A declared library list is authoritative: remove remote canvas-managed
    // libraries that are no longer listed, mirroring fonts' replace
    // semantics.
    for (const id of planIconLibraryDeletions(
      remote,
      localIds,
      authoritative,
    )) {
      try {
        await api.deleteIconLibrary(id);
        deletions.push({ id, operation: 'delete', success: true, errors: [] });
      } catch (error) {
        deletions.push({
          id,
          success: false,
          errors: [formatErrorMessage(error)],
        });
      }
    }
  }

  const outcomes = [
    ...[...outcomeByIndex.entries()]
      .sort(([a], [b]) => a - b)
      .map(([, outcome]) => outcome),
    ...deletions,
  ];

  return {
    outcomes,
    created: outcomes.filter((o) => o.operation === 'create').length,
    updated: outcomes.filter((o) => o.operation === 'update').length,
    unchanged: outcomes.filter((o) => o.operation === 'unchanged').length,
    deleted: outcomes.filter((o) => o.operation === 'delete').length,
    failed: outcomes.filter((o) => !o.success).length,
  };
}

const OPERATION_RESULT_LABELS: Record<IconPushOperation, string> = {
  create: 'Created',
  update: 'Updated',
  unchanged: 'Unchanged',
  delete: 'Deleted',
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
      const counts =
        outcome.uploadedCount !== undefined &&
        outcome.skippedCount !== undefined
          ? ` (${outcome.uploadedCount} uploaded, ${outcome.skippedCount} unchanged)`
          : '';
      results.push({
        itemName,
        success: true,
        details: [
          { content: `${OPERATION_RESULT_LABELS[outcome.operation]}${counts}` },
        ],
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
  operationLabels: { create: string; update: string; delete: string },
  authoritative: boolean,
): Result[] {
  const planned: Result[] = localLibraryIds.map((id) => ({
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
  for (const id of planIconLibraryDeletions(
    remoteLibraries,
    localLibraryIds,
    authoritative,
  )) {
    planned.push({
      itemName: id,
      itemType: 'Icon library',
      success: true,
      details: [{ content: operationLabels.delete }],
    });
  }
  return planned;
}
