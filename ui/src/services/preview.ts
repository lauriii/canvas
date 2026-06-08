import { createSelector } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';

import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import { setLayoutModel } from '@/features/layout/layoutModelSlice';
import { setHtml, setSnapshotHTML } from '@/features/pagePreview/previewSlice';
import {
  baseQueryWithAutoSaves,
  popCanvasLayoutRequest,
  pushCanvasLayoutRequest,
} from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

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
        try {
          const { data, meta } = await queryFulfilled;
          const { html, autoSaves } = data;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
          dispatch(setHtml(html));
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
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
      { html: string },
      {
        entityType: string;
        entityId: string;
        language: string;
        isTemplate?: boolean;
        templateInfo?: { bundle?: string; viewMode?: string };
      }
    >({
      query: ({ entityType, entityId, language, isTemplate, templateInfo }) => {
        const url = isTemplate
          ? `${language}/canvas/api/v0/layout-content-template/${entityType}.${templateInfo?.bundle}.${templateInfo?.viewMode}/${entityId}`
          : `${language}/canvas/api/v0/layout/${entityType}/${entityId}`;
        return { url, method: 'GET' };
      },
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          const { html } = data;
          // Update the snapshot HTML for preview-only display.
          dispatch(setSnapshotHTML(html));
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
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        // Pass update preview false to prevent a subsequent preview update,
        // we have the data here.
        dispatch(setLayoutModel({ layout, model, updatePreview: false }));
      },
    }),
  }),
});

export const {
  usePostPreviewMutation,
  useGetSnapshotPreviewQuery,
  useUpdateComponentMutation,
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

    const arg = {
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model,
    };

    // Prevent duplicate requests
    const stringBody = JSON.stringify(arg);
    if (stringBody === lastBody) {
      // Return a resolved promise to mimic successful completion
      return Promise.resolve({ data: undefined }) as any;
    }
    lastBody = stringBody;
    return updateComponent(arg);
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

// Module-level queue state for postPreview requests.
// Prevents parallel requests to the endpoint - only the most recent
// queued request executes when the active one completes.
let activePreviewRequest: Promise<PostPreviewResult> | null = null;
let pendingPreviewArg: PostPreviewArg | null = null;

// Incremented each time a postPreview request completes successfully.
// Used to detect whether an error has been superseded by a later success.
let previewSuccessCount = 0;

/**
 * Queued version of usePostPreviewMutation that prevents parallel requests.
 *
 * When a request is in flight, subsequent calls are queued. Only the most
 * recent queued request executes when the active one completes - earlier
 * queued requests never resolve (their data would be stale since preview
 * values are cumulative).
 *
 * This prevents entity lock contention on the backend.
 */
export function useQueuedPostPreviewMutation(
  options?: Parameters<typeof usePostPreviewMutation>[0],
): [
  (arg: PostPreviewArg) => Promise<PostPreviewResult>,
  ReturnType<typeof usePostPreviewMutation>[1],
] {
  const [postPreview, mutationState] = usePostPreviewMutation(options);

  const queuedPostPreview = async (
    arg: PostPreviewArg,
  ): Promise<PostPreviewResult> => {
    if (activePreviewRequest) {
      pendingPreviewArg = arg;
      await activePreviewRequest.catch(() => {});
      if (pendingPreviewArg !== arg) {
        // Superseded by a newer request - never resolve
        return new Promise(() => {});
      }
      pendingPreviewArg = null;
    }

    activePreviewRequest = postPreview(arg).unwrap();
    try {
      return await activePreviewRequest;
    } finally {
      activePreviewRequest = null;
      if (pendingPreviewArg) {
        const nextArg = pendingPreviewArg;
        pendingPreviewArg = null;
        queuedPostPreview(nextArg);
      }
    }
  };

  return [queuedPostPreview, mutationState];
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
