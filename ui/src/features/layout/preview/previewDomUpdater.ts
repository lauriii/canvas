/**
 * Redux middleware applying structural operations (move, delete, duplicate,
 * sort, shift, insert) to the live preview DOM optimistically: the preview
 * paints before any network activity, and persistence follows on the
 * decoupled auto-save cadence.
 *
 * Move, delete, duplicate, sort, and shift never change any component's
 * markup, so they are pure marker-range DOM operations. Insert needs the new
 * component's markup, so it persists first and renders through the partial
 * render endpoint. When a DOM operation cannot be applied (missing markers,
 * no active iframe, headless mode), the reducer's own `updatePreview` flag
 * stays set and the legacy full-render flow takes over.
 */
import {
  deleteNode,
  duplicateNode,
  insertNodes,
  moveNode,
  setUpdatePreview,
  shiftNode,
  sortNode,
} from '@/features/layout/layoutModelSlice';
import { schedulePersist } from '@/features/layout/preview/autoSavePersister';
import {
  findParentInfo,
  renderInsertedComponents,
} from '@/features/layout/preview/partialRender';
import {
  findTreeForUuid,
  notifyTreeEdited,
  resetKnownLibraries,
} from '@/features/layout/preview/previewTreeState';
import { getActivePreviewDocument } from '@/features/layout/preview/subtreeApply';
import {
  setPageData,
  updatePageDataExternally,
} from '@/features/pageData/pageDataSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
import {
  cloneMarkerRangeWithUuidMap,
  findInsertionPoint,
  findMarkerRange,
  moveMarkerRange,
  removeMarkerRange,
} from '@/utils/markerRange';
import { getPreviewPerformanceFlags } from '@/utils/previewCadence';
import { previewPerfMark } from '@/utils/previewPerf';

import type { Middleware } from '@reduxjs/toolkit';
import type {
  ComponentNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';

const selectPresentLayout = (state: any): RegionNode[] =>
  state.layoutModel.present.layout;

const isEntityContext = (state: any): boolean =>
  state.ui?.editorFrameContext === 'entity';

/**
 * Resolves the DOM insertion point for a component from its (post-reducer)
 * position in the layout: before the next sibling that exists in the DOM, or
 * appended to its container.
 */
const insertionPointFromLayout = (
  doc: Document,
  layout: RegionNode[],
  uuid: string,
  excludeUuid?: string,
) => {
  const parent = findParentInfo(layout, uuid);
  if (!parent) {
    return null;
  }
  for (let i = parent.index + 1; i < parent.siblings.length; i++) {
    const sibling = parent.siblings[i];
    if (sibling.uuid !== excludeUuid && findMarkerRange(doc, sibling.uuid)) {
      return findInsertionPoint(doc, parent.container, sibling.uuid);
    }
  }
  return findInsertionPoint(doc, parent.container, null);
};

/** Maps every uuid in a duplicated subtree to its counterpart. */
const buildUuidMap = (
  original: ComponentNode,
  duplicate: ComponentNode,
  map: Record<string, string> = {},
): Record<string, string> => {
  map[original.uuid] = duplicate.uuid;
  (original.slots ?? []).forEach((slot, slotIndex) => {
    (slot.components ?? []).forEach((child, childIndex) => {
      const counterpart =
        duplicate.slots?.[slotIndex]?.components?.[childIndex];
      if (counterpart) {
        buildUuidMap(child, counterpart, map);
      }
    });
  });
  return map;
};

export const previewDomUpdaterMiddleware: Middleware =
  (store) => (next) => (action: any) => {
    const flags = getPreviewPerformanceFlags();

    // A full document arriving means the preview iframe reloads: the tracked
    // asset library state belongs to the old document.
    if (setHtml.match(action) && action.payload) {
      resetKnownLibraries();
      return next(action);
    }

    const preState = store.getState();
    if (!isEntityContext(preState)) {
      return next(action);
    }

    // Page-data edits do not require any preview render (frozen regions
    // absorb title/breadcrumb changes); they only need persistence.
    if (
      (setPageData.match(action) || updatePageDataExternally.match(action)) &&
      flags.decoupledAutoSave
    ) {
      notifyTreeEdited('content');
      schedulePersist(store);
      return next(action);
    }

    const isStructuralOp =
      deleteNode.match(action) ||
      moveNode.match(action) ||
      sortNode.match(action) ||
      shiftNode.match(action) ||
      duplicateNode.match(action) ||
      insertNodes.match(action);
    if (!isStructuralOp) {
      return next(action);
    }

    const preLayout = selectPresentLayout(preState);
    const result = next(action);
    const postLayout = selectPresentLayout(store.getState());

    // Track which trees the operation touched, before attempting any DOM
    // work: the persist/freeze bookkeeping must reflect edits regardless of
    // which paint path ends up running.
    const payload: any = action.payload;
    const opUuid: string | undefined = deleteNode.match(action)
      ? action.payload
      : (payload?.uuid ?? payload?.useUUID);
    if (opUuid) {
      const preTree = findTreeForUuid(preLayout, opUuid);
      const postTree = findTreeForUuid(postLayout, opUuid);
      notifyTreeEdited(preTree, { structural: true });
      if (postTree !== preTree) {
        notifyTreeEdited(postTree, { structural: true });
      }
    } else {
      notifyTreeEdited('content', { structural: true });
      notifyTreeEdited('regions', { structural: true });
    }
    if (flags.decoupledAutoSave) {
      schedulePersist(store);
    }

    // Optimistic paint without decoupled persistence would cancel the full
    // render that is also the write path, losing the edit: require both.
    if (!flags.optimisticOps || !flags.decoupledAutoSave) {
      return result;
    }
    const doc = getActivePreviewDocument();
    if (!doc) {
      return result;
    }

    let applied = false;
    if (deleteNode.match(action)) {
      applied = removeMarkerRange(doc, action.payload);
    } else if (
      moveNode.match(action) ||
      sortNode.match(action) ||
      shiftNode.match(action)
    ) {
      const uuid = action.payload.uuid;
      if (uuid) {
        const point = insertionPointFromLayout(doc, postLayout, uuid, uuid);
        applied = !!point && moveMarkerRange(doc, uuid, point);
      }
    } else if (duplicateNode.match(action)) {
      const uuid = action.payload.uuid;
      const parent = findParentInfo(postLayout, uuid);
      const duplicate = parent?.siblings[parent.index + 1];
      const originalRange = findMarkerRange(doc, uuid);
      if (parent && duplicate && originalRange?.end.parentNode) {
        const clones = cloneMarkerRangeWithUuidMap(
          doc,
          uuid,
          buildUuidMap(parent.siblings[parent.index], duplicate),
          {
            parent: originalRange.end.parentNode,
            before: originalRange.end.nextSibling,
          },
        );
        applied = clones !== null;
      }
    } else if (insertNodes.match(action)) {
      // The new component's markup does not exist client-side: persist, then
      // render it through the partial render endpoint and splice it in.
      const useUUID = action.payload.useUUID;
      const count = action.payload.layoutModel?.layout?.length ?? 0;
      const parent = useUUID ? findParentInfo(postLayout, useUUID) : null;
      if (flags.partialRender && flags.decoupledAutoSave && parent && count) {
        const uuids = parent.siblings
          .slice(parent.index, parent.index + count)
          .map((component) => component.uuid);
        applied = true;
        (store.dispatch as any)(renderInsertedComponents(uuids));
      }
    }

    if (applied) {
      previewPerfMark('optimistic-op-applied', { type: action.type });
      // The preview already reflects the change: cancel the full render the
      // reducer requested. Persistence is already scheduled above.
      store.dispatch(setUpdatePreview(false));
    }
    return result;
  };
