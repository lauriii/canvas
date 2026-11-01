import { createSelector } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';

import { useAppStore } from '@/app/hooks';
import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import {
  selectLayout,
  selectModel,
  setLayoutModel,
  setTranslations,
} from '@/features/layout/layoutModelSlice';
import { spliceComponentHtml } from '@/features/layout/preview/inPlaceUpdate';
import {
  setHtml,
  setSnapshotHTML,
  setSnapshotTitle,
} from '@/features/pagePreview/previewSlice';
import {
  baseQueryWithAutoSaves,
  extractEntityParams,
  popCanvasLayoutRequest,
  pushCanvasLayoutRequest,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';
import { createPatchScheduler } from '@/services/patchScheduler';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';
import { getEntityTitle } from '@/utils/entityTitle';

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

// The response to a PATCH with partialPreview: the updated instance's preview
// markup and model only.
type PartialUpdateComponentResultType = {
  component: { uuid: string; html: string };
  model: any;
  autoSaves: AutoSavesHash;
};

export type UpdateComponentQueryArg = {
  type: EditorFrameContext;
  componentInstanceUuid: string;
  componentType: string;
  model: Omit<ComponentModel, 'name'> | Omit<EvaluatedComponentModel, 'name'>;
  // The editor URL when the change was made, for changes sent later. Without
  // it, the entity is taken from the current URL when the request is sent.
  editorUrl?: string;
};

// Paces PATCHes from usePatchComponent() and usePatchProp(). Sends through the
// newest updateComponent trigger.
let sendPatch: (arg: UpdateComponentQueryArg) => PromiseLike<unknown>;
const patchScheduler = createPatchScheduler<UpdateComponentQueryArg>((arg) =>
  sendPatch(arg),
);

/**
 * Sends component changes waiting in the queue, and resolves once their
 * responses are applied. Call before reading a component's model to PATCH it.
 */
export const sendQueuedComponentPatches = () => patchScheduler.drain();

// Set when the preview lacks an instance a partial preview was returned for,
// until a PATCH returns the whole page.
let needsFullPreview = false;

// Per component instance, the newest successful response whose model was not
// applied because a newer PATCH was queued. Applied if that PATCH fails.
const supersededResponses = new Map<string, { model: any; layout?: any }>();

const isOtherEntity = (editorUrl?: string) =>
  editorUrl !== undefined &&
  JSON.stringify(extractEntityParams(editorUrl)) !==
    JSON.stringify(extractEntityParams(window.location.href));

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
      {
        html: string;
        entity_form_fields?: Record<string, unknown>;
        translations?: Record<string, any>;
      },
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
          const { html, entity_form_fields, translations } = data;
          // Update the snapshot HTML for preview-only display.
          dispatch(setSnapshotHTML(html));
          // Update the snapshot title for the translated page title display.
          const title = getEntityTitle(
            arg.entityType,
            (entity_form_fields as Record<string, string>) || {},
          );
          dispatch(setSnapshotTitle(title || ''));
          // Update translations for template component overrides.
          if (translations) {
            dispatch(setTranslations(translations));
          }
        } catch {
          // Error is handled by the component.
        }
      },
    }),
    updateComponent: builder.mutation<
      UpdateComponentResultType | PartialUpdateComponentResultType,
      UpdateComponentQueryArg
    >({
      query: ({ type, editorUrl, ...body }) => {
        let url = '';
        if (type === 'entity') {
          url = 'canvas/api/v0/layout/{entity_type}/{entity_id}';
        } else if (type === 'template') {
          url =
            'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}';
        } else if (type === 'pattern') {
          url = 'canvas/api/v0/layout-pattern/{entity_id}';
        }
        if (editorUrl) {
          const { entityType, entityId, templateBundle, templateViewMode } =
            extractEntityParams(editorUrl);
          url = replaceEntityParamsInUrl(
            url,
            entityType,
            entityId,
            templateBundle,
            templateViewMode,
          );
        }
        // Only entities support a partial preview.
        return {
          url,
          method: 'PATCH',
          body:
            type === 'entity' && !needsFullPreview
              ? { ...body, partialPreview: true }
              : body,
        };
      },
      async onQueryStarted(arg, { dispatch, getState, queryFulfilled }) {
        const uuid = arg.componentInstanceUuid;
        const applyModel = ({
          model,
          layout,
        }: {
          model: any;
          layout?: any;
        }) => {
          const state = getState() as RootState;
          // Pass update preview false to prevent a subsequent preview update,
          // we have the data here.
          dispatch(
            setLayoutModel(
              layout === undefined
                ? {
                    layout: selectLayout(state),
                    model: { ...selectModel(state), ...model },
                    updatePreview: false,
                  }
                : { layout, model, updatePreview: false },
            ),
          );
        };
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
          // The model of a successful response this one superseded is now the
          // newest saved one.
          const superseded = supersededResponses.get(uuid);
          if (superseded && !patchScheduler.has(uuid)) {
            supersededResponses.delete(uuid);
            applyModel(superseded);
          }
          return;
        } finally {
          // Tell ajax calls they're good to go regardless of success/failure.
          popCanvasLayoutRequest();
        }
        const { model, autoSaves } = data;
        // The editor moved to another entity since this change was made.
        if (isOtherEntity(arg.editorUrl)) {
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
          return;
        }
        // A queued PATCH for this instance will bring a newer model. Applying
        // this one would undo what was typed since this one was sent.
        const isOutdated = patchScheduler.has(uuid);
        if (!isOutdated) {
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
        }
        // Clear any stale snapshot (e.g. from a prior language/template preview)
        // so selectPreviewHtml returns this fresh editor html instead of the
        // preview snapshot, which selectPreviewHtml prefers while set.
        dispatch(setSnapshotHTML(''));
        dispatch(setSnapshotTitle(''));
        const state = getState() as RootState;
        if ('component' in data) {
          const html = spliceComponentHtml(state.preview.html, data.component);
          if (html === null) {
            // The current preview lacks the instance: send the change again,
            // in turn with other changes, for the whole page.
            needsFullPreview = true;
            if (!patchScheduler.has(uuid)) {
              sendPatch ??= (patch) =>
                dispatch(previewApi.endpoints.updateComponent.initiate(patch));
              patchScheduler.schedule(uuid, arg, true);
            }
            return;
          }
          dispatch(setHtml(html));
        } else {
          needsFullPreview = false;
          dispatch(setHtml(data.html));
        }
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        const response = 'component' in data ? { model } : data;
        if (isOutdated) {
          supersededResponses.set(uuid, response);
          return;
        }
        supersededResponses.delete(uuid);
        applyModel(response);
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
      editorUrl: window.location.href,
    };

    // Prevent duplicate requests
    const stringBody = JSON.stringify(arg);
    if (stringBody === lastBody) {
      // Return a resolved promise to mimic successful completion
      return Promise.resolve({ data: undefined }) as any;
    }
    lastBody = stringBody;
    sendPatch = updateComponent;
    patchScheduler.schedule(selectedComponent, arg);
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
 * data). Does nothing if the current model is not evaluated.
 */
export const usePatchProp = () => {
  const [updateComponent] = useUpdateComponentMutation();
  const store = useAppStore();

  return async (
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
    } = inputUIData;

    // Build on the model after queued changes are saved; otherwise sending
    // them would undo this change.
    await sendQueuedComponentPatches();
    const selectedModel = selectModel(store.getState())[selectedComponent];
    if (!selectedModel || !('source' in selectedModel)) {
      return;
    }

    sendPatch = updateComponent;
    patchScheduler.schedule(
      selectedComponent,
      {
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
        editorUrl: window.location.href,
      },
      true,
    );
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
 * Waits for all active or queued preview requests to complete.
 *
 * Required before operations that move the auto-save entry (like changing the
 * langcode) to ensure requests-in-progress aren't written to the old key.
 */
export async function waitForPreviewRequests(): Promise<void> {
  while (activePreviewRequest) {
    await activePreviewRequest.catch(() => {});
  }
}

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
