/**
 * Orchestrates the stateless partial render endpoint: blast-radius routing,
 * latest-wins ordering per subtree, asset delta application, and in-place DOM
 * swaps. Falls back to one full preview render on anything it cannot apply.
 */
import {
  selectLayout,
  selectModel,
  setLayoutModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  flushPersist,
  schedulePersist,
} from '@/features/layout/preview/autoSavePersister';
import {
  findTreeForUuid,
  getKnownLibraries,
  isStructureDirty,
  notifyTreeEdited,
  setKnownLibraries,
} from '@/features/layout/preview/previewTreeState';
import {
  applyComponentHtml,
  getActivePreviewDocument,
  getPreviewAjaxPageStateLibraries,
  importMapIsSatisfied,
  injectAssets,
} from '@/features/layout/preview/subtreeApply';
import { previewApi } from '@/services/preview';
import {
  createExecutableFragment,
  findInsertionPoint,
  findMarkerRange,
} from '@/utils/markerRange';
import { previewPerfMark } from '@/utils/previewPerf';

import type { AppThunk } from '@/app/store';
import type {
  ComponentModels,
  ComponentNode,
  RegionNode,
} from '@/features/layout/layoutModelSlice';
import type { RenderComponentsResultType } from '@/services/preview';

/**
 * Monotonic token for latest-wins ordering: the server echoes it opaquely.
 * Pre-collaboration this is a client counter; post-collaboration it becomes
 * the op log sequence number.
 */
let tokenCounter = 0;

/** In-flight render per subtree root; superseded requests are aborted. */
const inFlight = new Map<string, { abort: () => void; token: number }>();

/**
 * Model values sent for rendering whose server-normalized echo has not
 * landed yet; the persister overlays these so a flush never writes stale
 * inputs.
 */
const pendingModelOverrides = new Map<string, unknown>();

export const getConsolidatedModelOverrides = (): Record<string, unknown> =>
  Object.fromEntries(pendingModelOverrides);

interface ParentInfo {
  container: { regionId?: string; slotId?: string };
  siblings: ComponentNode[];
  index: number;
}

/**
 * Locates a component's parent container and sibling list in the layout.
 */
export function findParentInfo(
  layout: RegionNode[],
  uuid: string,
): ParentInfo | null {
  const search = (
    components: ComponentNode[],
    container: ParentInfo['container'],
  ): ParentInfo | null => {
    for (let i = 0; i < components.length; i++) {
      const component = components[i];
      if (component.uuid === uuid) {
        return { container, siblings: components, index: i };
      }
      for (const slot of component.slots ?? []) {
        const result = search(slot.components ?? [], { slotId: slot.id });
        if (result) {
          return result;
        }
      }
    }
    return null;
  };
  for (const region of layout) {
    const result = search(region.components ?? [], { regionId: region.id });
    if (result) {
      return result;
    }
  }
  return null;
}

/**
 * The subtree that must be re-rendered for an edit to the given component:
 * the outermost code-component (island) ancestor when one exists, because
 * fresh markup for a component nested in an island's slot lands inside the
 * ancestor's un-hydrated island template where it cannot be addressed.
 */
export function resolveRenderRoot(layout: RegionNode[], uuid: string): string {
  let outermostIsland: string | null = null;
  const visit = (
    components: ComponentNode[],
    islandAncestor: string | null,
  ): boolean => {
    for (const component of components) {
      const ancestor =
        islandAncestor ??
        (component.type?.startsWith('js.') ? component.uuid : null);
      if (component.uuid === uuid) {
        outermostIsland = islandAncestor;
        return true;
      }
      for (const slot of component.slots ?? []) {
        if (visit(slot.components ?? [], ancestor)) {
          return true;
        }
      }
    }
    return false;
  };
  for (const region of layout) {
    if (visit(region.components ?? [], null)) {
      break;
    }
  }
  return outermostIsland ?? uuid;
}

/**
 * Collects the client model for a subtree (root plus slot descendants), with
 * the edited component's model overridden.
 */
function collectSubtreeModel(
  layout: RegionNode[],
  model: ComponentModels,
  rootUuid: string,
  overrides: Record<string, unknown>,
): Record<string, unknown> {
  const result: Record<string, unknown> = {};
  const collect = (component: ComponentNode): void => {
    const value = overrides[component.uuid] ?? model[component.uuid];
    if (value) {
      result[component.uuid] = value;
    }
    for (const slot of component.slots ?? []) {
      (slot.components ?? []).forEach(collect);
    }
  };
  const findNode = (components: ComponentNode[]): ComponentNode | null => {
    for (const component of components) {
      if (component.uuid === rootUuid) {
        return component;
      }
      for (const slot of component.slots ?? []) {
        const found = findNode(slot.components ?? []);
        if (found) {
          return found;
        }
      }
    }
    return null;
  };
  for (const region of layout) {
    const node = findNode(region.components ?? []);
    if (node) {
      collect(node);
      break;
    }
  }
  return result;
}

const librariesForRequest = (): string[] | string => {
  const known = getKnownLibraries();
  if (known) {
    return known;
  }
  const doc = getActivePreviewDocument();
  return (doc && getPreviewAjaxPageStateLibraries(doc)) || [];
};

const fallbackToFullRender: AppThunk = (dispatch) => {
  dispatch(setUpdatePreview(true));
};

const applyRenderResult = (
  result: RenderComponentsResultType,
): 'applied' | 'fallback' => {
  const doc = getActivePreviewDocument();
  if (!doc) {
    return 'fallback';
  }
  // New import-map entries cannot be added to the already-processed map:
  // take one full reload (which rebuilds the map with every needed entry);
  // subsequent edits resume partial rendering.
  if (!importMapIsSatisfied(doc, result.assets?.importMap ?? {})) {
    return 'fallback';
  }
  injectAssets(doc, result.assets);
  if (result.assets?.libraries) {
    setKnownLibraries(result.assets.libraries);
  }
  for (const [uuid, html] of Object.entries(result.html)) {
    if (!applyComponentHtml(doc, uuid, html)) {
      return 'fallback';
    }
  }
  return 'applied';
};

/**
 * Renders one component's subtree through the partial render endpoint and
 * swaps it in place. Used for prop edits on server-rendered components.
 */
export const requestComponentRender =
  (editedUuid: string, editedModel: unknown): AppThunk =>
  async (dispatch, getState) => {
    const state = getState();
    const layout = selectLayout(state);
    const model = selectModel(state);
    const renderRoot = resolveRenderRoot(layout, editedUuid);

    notifyTreeEdited(findTreeForUuid(layout, editedUuid));
    pendingModelOverrides.set(editedUuid, editedModel);
    // Rendering is pure: persistence rides its own debounced cadence.
    schedulePersist({ dispatch, getState } as any);

    // The endpoint renders from the server draft structure: a pending
    // structural change (e.g. an unpersisted duplicate) must land first.
    if (isStructureDirty()) {
      await flushPersist();
    }

    const token = ++tokenCounter;
    inFlight.get(renderRoot)?.abort();
    const request = dispatch(
      previewApi.endpoints.renderComponents.initiate({
        uuids: [renderRoot],
        model: collectSubtreeModel(layout, model, renderRoot, {
          [editedUuid]: editedModel,
        }),
        libraries: librariesForRequest(),
        token,
      }),
    );
    inFlight.set(renderRoot, { abort: () => request.abort(), token });
    previewPerfMark('partial-render-request', { uuid: renderRoot });

    try {
      const result = await request.unwrap();
      if (inFlight.get(renderRoot)?.token !== token) {
        // Superseded while in flight: the newer request repaints.
        return;
      }
      inFlight.delete(renderRoot);
      if (applyRenderResult(result) === 'fallback') {
        dispatch(fallbackToFullRender);
        return;
      }
      // Adopt the server-normalized model echo for the rendered subtree so
      // the component form rebuild logic sees the same shapes as the PATCH
      // flow, then drop the consolidation overrides it covers.
      const currentModel = selectModel(getState());
      Object.keys(result.model ?? {}).forEach((uuid) =>
        pendingModelOverrides.delete(uuid),
      );
      dispatch(
        setLayoutModel({
          layout: selectLayout(getState()),
          model: { ...currentModel, ...(result.model ?? {}) },
          updatePreview: false,
        }),
      );
    } catch (error: any) {
      if (inFlight.get(renderRoot)?.token !== token) {
        // Superseded: the abort raced this rejection; the newer request owns
        // the paint.
        return;
      }
      inFlight.delete(renderRoot);
      if (error?.name === 'AbortError') {
        return;
      }
      dispatch(fallbackToFullRender);
    }
  };

/**
 * Inserts freshly rendered markup for components that do not exist in the
 * preview DOM yet, positioned from the client layout.
 */
const spliceComponentHtml = (
  doc: Document,
  layout: RegionNode[],
  uuid: string,
  html: string,
): boolean => {
  const parent = findParentInfo(layout, uuid);
  if (!parent) {
    return false;
  }
  // Insert before the next sibling that already exists in the DOM; append to
  // the container when none does.
  let point = null;
  for (let i = parent.index + 1; i < parent.siblings.length && !point; i++) {
    const sibling = parent.siblings[i];
    if (findMarkerRange(doc, sibling.uuid)) {
      point = findInsertionPoint(doc, parent.container, sibling.uuid);
    }
  }
  point = point ?? findInsertionPoint(doc, parent.container, null);
  if (!point) {
    return false;
  }
  const fragment = createExecutableFragment(doc, html);
  const inserted = Array.from(fragment.childNodes);
  point.parent.insertBefore(fragment, point.before);
  // The empty-region placeholder no longer applies once content exists.
  if (point.parent instanceof Element) {
    point.parent
      .querySelector(':scope > .canvas--region-empty-placeholder')
      ?.remove();
  }
  const win = doc.defaultView as any;
  if (win?.Drupal?.attachBehaviors) {
    inserted
      .filter((node) => node.nodeType === Node.ELEMENT_NODE)
      .forEach((node) => {
        try {
          win.Drupal.attachBehaviors(node, win.drupalSettings);
        } catch {
          // A behavior throwing must not break the preview update.
        }
      });
  }
  return true;
};

/**
 * Persist-then-render insert flow: the endpoint renders draft state, so the
 * new component must be persisted before it can be rendered, then its markup
 * is spliced at the target marker position without a document reload.
 */
export const renderInsertedComponents =
  (uuids: string[]): AppThunk =>
  async (dispatch, getState) => {
    await flushPersist();
    const token = ++tokenCounter;
    const state = getState();
    const layout = selectLayout(state);
    const model = selectModel(state);
    const request = dispatch(
      previewApi.endpoints.renderComponents.initiate({
        uuids,
        model: uuids.reduce(
          (carry, uuid) => ({
            ...carry,
            ...collectSubtreeModel(layout, model, uuid, {}),
          }),
          {},
        ),
        libraries: librariesForRequest(),
        token,
      }),
    );
    previewPerfMark('insert-render-request', { uuids });
    try {
      const result = await request.unwrap();
      const doc = getActivePreviewDocument();
      if (!doc || !importMapIsSatisfied(doc, result.assets?.importMap ?? {})) {
        dispatch(fallbackToFullRender);
        return;
      }
      injectAssets(doc, result.assets);
      if (result.assets?.libraries) {
        setKnownLibraries(result.assets.libraries);
      }
      const currentLayout = selectLayout(getState());
      for (const [uuid, html] of Object.entries(result.html)) {
        if (!spliceComponentHtml(doc, currentLayout, uuid, html)) {
          dispatch(fallbackToFullRender);
          return;
        }
      }
      previewPerfMark('insert-render-applied', { uuids });
    } catch {
      dispatch(fallbackToFullRender);
    }
  };

/**
 * Routes a component prop edit to the cheapest correct paint path.
 *
 * - Island fast path (the form already painted optimistically): schedule the
 *   authoritative PATCH via the caller's fallback.
 * - Bounded server-rendered edit: partial render plus debounced persist.
 * - Anything else (template context, flags off): the caller's fallback
 *   (the legacy PATCH flow).
 */
export const dispatchComponentPropEdit =
  (options: {
    uuid: string;
    model: unknown;
    isEntityContext: boolean;
    partialRenderEnabled: boolean;
    fallbackPatch: () => void;
  }): AppThunk =>
  (dispatch) => {
    const { uuid, model, isEntityContext, partialRenderEnabled } = options;
    if (!isEntityContext || !partialRenderEnabled) {
      options.fallbackPatch();
      return;
    }
    void dispatch(requestComponentRender(uuid, model));
  };
