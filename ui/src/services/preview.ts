import { createSelector } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';

import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import {
  setLayoutModel,
  setTranslations,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import { flushPersist } from '@/features/layout/preview/autoSavePersister';
import {
  findTreeForUuid,
  isStructureDirty,
  isTreeDirty,
  markPersisted,
  snapshotEditVersions,
} from '@/features/layout/preview/previewTreeState';
import { applySubtreeFromFullPageHtml } from '@/features/layout/preview/subtreeApply';
import { setHtml, setSnapshotHTML } from '@/features/pagePreview/previewSlice';
import {
  baseQueryWithAutoSaves,
  popCanvasLayoutRequest,
  pushCanvasLayoutRequest,
} from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { enqueueLayoutWrite } from '@/services/previewWriteChain';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';
import { getPreviewPerformanceFlags } from '@/utils/previewCadence';
import { previewPerfMark } from '@/utils/previewPerf';

import type { RootState } from '@/app/store';
import type {
  ComponentModel,
  EvaluatedComponentModel,
  PropSource,
  ResolvedValues,
} from '@/features/layout/layoutModelSlice';
import type { EditorFrameContext } from '@/features/ui/uiSlice';
import type { ConflictError } from '@/services/pendingChangesApi';
import type { AutoSavesHash } from '@/types/AutoSaves';
import type { InputUIData } from '@/types/Form';

export type UpdateComponentResultType = {
  html: string;
  layout: any;
  model: any;
  autoSaves: AutoSavesHash;
  errors?: Array<ConflictError>;
};

export type UpdateComponentQueryArg = {
  type: EditorFrameContext;
  componentInstanceUuid: string;
  componentType: string;
  model: Omit<ComponentModel, 'name'> | Omit<EvaluatedComponentModel, 'name'>;
  /** The tree this PATCH does not touch; set by usePatchComponent. */
  frozen?: 'regions' | 'content';
};

export type RenderComponentsQueryArg = {
  uuids: string[];
  model?: Record<string, unknown>;
  libraries?: string[] | string;
  token?: number | string;
};

export type RenderComponentsResultType = {
  html: Record<string, string>;
  model: Record<string, any>;
  assets: {
    css: string[];
    js: string[];
    importMap: {
      imports?: Record<string, string>;
      scopes?: Record<string, Record<string, string>>;
    };
    libraries: string[];
  };
  token: number | string | null;
};

export type PersistLayoutQueryArg = {
  layout: any;
  model: any;
  entity_form_fields: any;
  frozen?: 'regions' | 'content';
};

export const previewApi = createApi({
  reducerPath: 'previewApi',
  baseQuery: baseQueryWithAutoSaves,
  endpoints: (builder) => ({
    postPreview: builder.mutation<
      { html: string; autoSaves: AutoSavesHash },
      {
        entityType: string;
        entityId: string;
        layout: any;
        model: any;
        entity_form_fields: any;
      }
    >({
      query: ({ entityType, entityId, ...body }) => ({
        url: `canvas/api/v0/layout/${entityType}/${entityId}`,
        method: 'POST',
        body,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        pushCanvasLayoutRequest();
        previewPerfMark('full-render-request-start');
        // Edits made while this request is in flight are not in its payload:
        // snapshot what this request actually persists.
        const persistSnapshot = snapshotEditVersions();
        try {
          const { data, meta } = await queryFulfilled;
          const { html, autoSaves } = data;
          previewPerfMark('full-render-request-end');
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
          dispatch(setHtml(html));
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
          // A successful full-document POST persisted everything it carried
          // and repainted the whole preview: the render request cycle is
          // complete until something requests a new one. Without decoupled
          // auto-save the legacy engine (updatePreview stays true, every
          // change re-POSTs) remains in charge.
          if (getPreviewPerformanceFlags().decoupledAutoSave) {
            markPersisted(persistSnapshot);
            dispatch(setUpdatePreview(false));
          }
          previewSuccessCount++;
          dispatch(setPostPreviewCompleted(true));
        } catch (error) {
          // A failed preview may be followed moments later by a successful one
          // (e.g. the user keeps editing). Capture the success count at the
          // time of failure and re-throw only if no successful request has
          // completed by the time the delay expires.
          const successCountAtFailure = previewSuccessCount;
          setTimeout(() => {
            if (previewSuccessCount === successCountAtFailure) {
              throw error;
            }
          }, 5000);
        } finally {
          popCanvasLayoutRequest();
        }
      },
    }),
    // Snapshot preview updates the preview frame without changing the active
    // model in the UI.
    getSnapshotPreview: builder.query<
      { html: string; translations?: Record<string, any> },
      {
        entityType: string;
        entityId: string;
        language: string;
        isTemplate?: boolean;
        templateInfo?: { bundle?: string; viewMode?: string };
      }
    >({
      query: ({ entityType, entityId, language, isTemplate, templateInfo }) => {
        // Request the translation via the `canvas_preview_langcode` query
        // parameter. The backend routes the request through the site's
        // configured language negotiation — redirecting when needed — so the
        // right translation is served regardless of the negotiation method.
        const path = isTemplate
          ? `canvas/api/v0/layout-content-template/${entityType}.${templateInfo?.bundle}.${templateInfo?.viewMode}/${entityId}`
          : `canvas/api/v0/layout/${entityType}/${entityId}`;
        const url = language
          ? `${path}?canvas_preview_langcode=${encodeURIComponent(language)}`
          : path;
        return { url, method: 'GET' };
      },
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          const { html, translations } = data;
          // Update the snapshot HTML for preview-only display.
          dispatch(setSnapshotHTML(html));
          if (translations) {
            dispatch(setTranslations(translations));
          }
        } catch {
          // Error is handled by the component.
        }
      },
    }),
    updateComponent: builder.mutation<
      UpdateComponentResultType,
      UpdateComponentQueryArg
    >({
      query: ({ type, ...body }) => {
        let url = '';
        if (type === 'entity') {
          url = 'canvas/api/v0/layout/{entity_type}/{entity_id}';
        } else if (type === 'template') {
          url =
            'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}';
        }
        return {
          url,
          method: 'PATCH',
          body,
        };
      },
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        // Force any ajax calls to wait.
        pushCanvasLayoutRequest();
        let data: any;
        let meta: any;
        try {
          ({ data, meta } = await queryFulfilled);
        } catch {
          // If the request fails (e.g. the server rejects an invalid field
          // value), we must still release the lock so that subsequent Drupal
          // AJAX calls are not permanently blocked.
          // @see https://www.drupal.org/project/canvas/issues/3579026
          return;
        } finally {
          // Tell ajax calls they're good to go regardless of success/failure.
          popCanvasLayoutRequest();
        }
        const { html, layout, model, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        // Apply the response as an in-place swap of just the edited
        // component's subtree: no srcdoc reload, no double-buffer swap, no
        // document-wide re-hydration wait.
        const applied =
          getPreviewPerformanceFlags().subtreePatching &&
          applySubtreeFromFullPageHtml(body.componentInstanceUuid, html);
        if (!applied) {
          if (body.frozen) {
            // A frozen response must never be applied as a full document
            // (its frozen tree is empty); request one full unfrozen
            // re-render to resynchronize instead.
            dispatch(setUpdatePreview(true));
          } else {
            // Clear any stale snapshot (e.g. from a prior language/template
            // preview) so selectPreviewHtml returns this fresh editor html
            // instead of the preview snapshot, which selectPreviewHtml
            // prefers while set.
            dispatch(setSnapshotHTML(''));
            dispatch(setHtml(html));
          }
        }
        // Pass update preview false to prevent a subsequent preview update,
        // we have the data here.
        dispatch(setLayoutModel({ layout, model, updatePreview: false }));
      },
    }),
    // Stateless partial render: renders only the requested component
    // instances as subtrees from draft state plus the given model overlay.
    // Pure function server-side, so requests are concurrent and abortable;
    // orchestration (latest-wins, asset injection, DOM apply) lives in
    // features/layout/preview/partialRender.ts.
    renderComponents: builder.mutation<
      RenderComponentsResultType,
      RenderComponentsQueryArg
    >({
      query: (body) => ({
        url: 'canvas/api/v0/layout/{entity_type}/{entity_id}/render',
        method: 'POST',
        body,
      }),
    }),
    // Persist-only auto-save: same document payload as postPreview, but the
    // server skips all rendering (`render: false`). Debounced and flushed by
    // features/layout/preview/autoSavePersister.ts.
    persistLayout: builder.mutation<
      { autoSaves: AutoSavesHash },
      PersistLayoutQueryArg
    >({
      query: (body) => ({
        url: 'canvas/api/v0/layout/{entity_type}/{entity_id}',
        method: 'POST',
        body: { ...body, render: false },
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        pushCanvasLayoutRequest();
        try {
          const { data, meta } = await queryFulfilled;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
          handleAutoSavesHashUpdate(dispatch, data.autoSaves, meta);
          dispatch(setPostPreviewCompleted(true));
        } finally {
          popCanvasLayoutRequest();
        }
      },
    }),
  }),
});

export const {
  usePostPreviewMutation,
  useGetSnapshotPreviewQuery,
  useUpdateComponentMutation,
  usePersistLayoutMutation,
} = previewApi;

let lastBody = {};
/**
 * A hook that wraps useUpdateComponentMutation with a simpler interface.
 *
 * Instead of manually constructing the full UpdateComponentQueryArg at every
 * call site, consumers pass only the model payload. The hook derives
 * `type`, `componentInstanceUuid`, and `componentType` from `inputUIData`.
 *
 * @param inputUIData - The return value of useInputUIData().
 */
export const usePatchComponent = () => {
  const [updateComponent] = useUpdateComponentMutation();

  return (
    inputUIData: InputUIData,
    model: UpdateComponentQueryArg['model'],
  ) => {
    const {
      selectedComponent,
      selectedComponentType,
      version,
      editorFrameContext,
    } = inputUIData;

    const flags = getPreviewPerformanceFlags();
    const arg: UpdateComponentQueryArg = {
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model,
    };
    // Declare the tree this PATCH does not touch as frozen, but only when it
    // has no unpersisted edits, and only when the response will be applied as
    // a subtree swap: a frozen response must never become the full document.
    if (
      flags.subtreePatching &&
      flags.frozenTrees &&
      editorFrameContext === 'entity'
    ) {
      const targetTree = findTreeForUuid(
        inputUIData.layout as any,
        selectedComponent,
      );
      const otherTree = targetTree === 'content' ? 'regions' : 'content';
      if (!isTreeDirty(otherTree)) {
        arg.frozen = otherTree;
      }
    }

    // Prevent duplicate requests
    const stringBody = JSON.stringify(arg);
    if (stringBody === lastBody) {
      // Return a resolved promise to mimic successful completion
      return Promise.resolve({ data: undefined }) as any;
    }
    lastBody = stringBody;
    // Auto-save writes stay ordered on the shared chain: a PATCH must not
    // overtake a pending persist for an earlier structural change, or the
    // server would resolve the component against a stale draft structure
    // (e.g. a prop edit on a not-yet-persisted duplicate). Flushing enqueues
    // the persist ahead of this PATCH.
    if (isStructureDirty()) {
      void flushPersist();
    }
    return enqueueLayoutWrite(() => updateComponent(arg));
  };
};

/**
 * A targeted variant of usePatchComponent for the common case of updating a
 * single prop on the current component.
 *
 * The caller provides only the prop name and its new source/resolved values.
 * The hook spreads the existing model's source and resolved, overriding just
 * the named prop — avoiding the repetitive spread boilerplate at every call
 * site.
 *
 * Only valid for EvaluatedComponentModel instances (components with source
 * data). Returns null without patching if the current model is not evaluated.
 */
export const usePatchProp = () => {
  const [updateComponent] = useUpdateComponentMutation();

  return (
    inputUIData: InputUIData,
    propName: string,
    sourceValue: PropSource | PropSource['value'],
    resolvedValue: ResolvedValues[string],
  ) => {
    const {
      selectedComponent,
      selectedComponentType,
      version,
      editorFrameContext,
      model,
    } = inputUIData;

    const selectedModel = model?.[selectedComponent];
    if (!selectedModel || !('source' in selectedModel)) {
      return null;
    }

    return updateComponent({
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model: {
        source: {
          ...selectedModel.source,
          [propName]: sourceValue,
        },
        resolved: {
          ...selectedModel.resolved,
          [propName]: resolvedValue,
        },
      },
    });
  };
};

// A selector that returns the current updateComponent mutation loading state
// given a component ID.
// For each API endpoint, RTK Query makes a .select method available allowing
// you to select the current state given a cache key. This returns a new
// function every time. As a result we must use createSelector to memoize it.
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
const createUpdateComponentSelector = createSelector(
  (componentInstanceId: string) => componentInstanceId,
  (componentInstanceId) =>
    previewApi.endpoints.updateComponent.select({
      fixedCacheKey: componentInstanceId,
      requestId: undefined,
    }),
);

type PostPreviewResult = { html: string; autoSaves: AutoSavesHash };
type PostPreviewArg = {
  layout: any;
  model: any;
  entity_form_fields: any;
  entityId: string;
  entityType: string;
};

// Incremented each time a postPreview request completes successfully.
// Used to detect whether an error has been superseded by a later success.
let previewSuccessCount = 0;

/**
 * usePostPreviewMutation with all auto-save writes kept in dispatch order.
 *
 * Full-document POSTs share one client-side write chain with persists and
 * PATCHes (see previewWriteChain.ts). Unlike the previous single-flight
 * queue, superseded callers' promises still settle with their own result.
 */
export function useOrderedPostPreviewMutation(
  options?: Parameters<typeof usePostPreviewMutation>[0],
): [
  (arg: PostPreviewArg) => Promise<PostPreviewResult>,
  ReturnType<typeof usePostPreviewMutation>[1],
] {
  const [postPreview, mutationState] = usePostPreviewMutation(options);
  const orderedPostPreview = (
    arg: PostPreviewArg,
  ): Promise<PostPreviewResult> =>
    enqueueLayoutWrite(() => postPreview(arg).unwrap());
  return [orderedPostPreview, mutationState];
}

// A selector that can be called from anywhere in the code base to
// determine the current update mutation loading state given a component
// instance ID. As createUpdateComponentSelector is memoized, we must also use
// createSelector here so that the subsequent selector is memoised.
// Returns false if componentInstanceId is undefined.
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
// @see https://redux.js.org/tutorials/fundamentals/part-7-standard-patterns#memoizing-selectors-with-createselector
export const selectUpdateComponentLoadingState: (
  state: RootState,
  componentInstanceId: string | undefined,
) => boolean = createSelector(
  [
    (state: RootState) => state,
    (_state: RootState, componentInstanceId: string | undefined) =>
      componentInstanceId,
  ],
  (state, componentInstanceId): boolean => {
    if (!componentInstanceId) {
      return false;
    }
    const selectUpdateComponentSelector =
      createUpdateComponentSelector(componentInstanceId);
    return selectUpdateComponentSelector(state).isLoading;
  },
);
