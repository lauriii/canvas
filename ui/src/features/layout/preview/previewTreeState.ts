/**
 * Client-side bookkeeping for the stateless per-request tree freeze and the
 * decoupled auto-save flow.
 *
 * Per-tree edit/persist version counters decide which tree can be declared
 * frozen on a request: a tree with unpersisted edits is never frozen, so the
 * fail-safe direction is always a full write and render. There is no
 * server-side freeze state and no thaw transition: the live preview DOM is
 * the snapshot, and this module only decides what the next request declares.
 *
 * @see docs/adr/0017-preview-partial-rendering-frozen-regions.md (canvas module)
 */
import { getPreviewPerformanceFlags } from '@/utils/previewCadence';

import type { RegionNode } from '@/features/layout/layoutModelSlice';

export type PreviewTree = 'content' | 'regions';

const editVersions: Record<PreviewTree, number> = { content: 0, regions: 0 };
const persistedVersions: Record<PreviewTree, number> = {
  content: 0,
  regions: 0,
};

/** Counts structural (layout-shape) edits separately: requests that render
 * from server draft state must flush persistence first when this is dirty. */
let structureEditVersion = 0;
let structurePersistedVersion = 0;

export const notifyTreeEdited = (
  tree: PreviewTree,
  options: { structural?: boolean } = {},
): void => {
  editVersions[tree]++;
  if (options.structural) {
    structureEditVersion++;
  }
};

export interface PersistSnapshot {
  content: number;
  regions: number;
  structure: number;
}

export const snapshotEditVersions = (): PersistSnapshot => ({
  content: editVersions.content,
  regions: editVersions.regions,
  structure: structureEditVersion,
});

export const markPersisted = (snapshot: PersistSnapshot): void => {
  persistedVersions.content = Math.max(
    persistedVersions.content,
    snapshot.content,
  );
  persistedVersions.regions = Math.max(
    persistedVersions.regions,
    snapshot.regions,
  );
  structurePersistedVersion = Math.max(
    structurePersistedVersion,
    snapshot.structure,
  );
};

/** A full-document POST persists everything it sent. */
export const markAllPersisted = (): void => {
  markPersisted(snapshotEditVersions());
};

export const isTreeDirty = (tree: PreviewTree): boolean =>
  editVersions[tree] !== persistedVersions[tree];

export const isStructureDirty = (): boolean =>
  structureEditVersion !== structurePersistedVersion;

export const isAnythingDirty = (): boolean =>
  isTreeDirty('content') || isTreeDirty('regions');

/**
 * The tree the next persist/PATCH request may declare frozen: the one with no
 * unpersisted edits — and only when the other one is dirty (when both trees
 * are clean or both are dirty there is nothing safe or useful to freeze).
 */
export const getFrozenTreeDeclaration = (): PreviewTree | undefined => {
  if (!getPreviewPerformanceFlags().frozenTrees) {
    return undefined;
  }
  if (!isTreeDirty('regions') && isTreeDirty('content')) {
    return 'regions';
  }
  if (!isTreeDirty('content') && isTreeDirty('regions')) {
    return 'content';
  }
  return undefined;
};

/**
 * Maps a component instance to the tree it lives in, from the client layout.
 */
export const findTreeForUuid = (
  layout: RegionNode[],
  uuid: string,
): PreviewTree => {
  for (const region of layout) {
    if (region.id === 'content') {
      continue;
    }
    let found = false;
    const visit = (components: any[]): void => {
      for (const component of components) {
        if (component.uuid === uuid) {
          found = true;
          return;
        }
        for (const slot of component.slots ?? []) {
          visit(slot.components ?? []);
        }
      }
    };
    visit(region.components ?? []);
    if (found) {
      return 'regions';
    }
  }
  return 'content';
};

/**
 * The expanded asset library list the preview document has, tracked across
 * partial renders. Null means "not yet known": the next render request sends
 * the preview document's own (compressed) ajaxPageState value instead.
 */
let knownLibraries: string[] | null = null;

export const getKnownLibraries = (): string[] | null => knownLibraries;
export const setKnownLibraries = (libraries: string[]): void => {
  knownLibraries = libraries;
};
/** Reset when the preview document fully reloads. */
export const resetKnownLibraries = (): void => {
  knownLibraries = null;
};
