import { createSelector } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';

import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
  setLayoutModel,
  setTranslations,
} from '@/features/layout/layoutModelSlice';
import { spliceComponentHtml } from '@/features/layout/preview/inPlaceUpdate';
import {
  getPreviewLibraries,
  loadPreviewAssets,
} from '@/features/layout/preview/previewAssets';
import {
  setHtml,
  setPartialHtml,
  setSnapshotHTML,
  setSnapshotTitle,
} from '@/features/pagePreview/previewSlice';
import {
  baseQueryWithAutoSaves,
  extractEntityParams,
  isOtherEntity,
  popCanvasLayoutRequest,
  pushCanvasLayoutRequest,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';
import {
  createPatchScheduler,
  rebaseComponentModel,
} from '@/services/patchScheduler';
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
import type { PreviewAssets } from '@/features/layout/preview/previewAssets';
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
  assets?: PreviewAssets;
};

export type UpdateComponentQueryArg = {
  type: EditorFrameContext;
  componentInstanceUuid: string;
  componentType: string;
  model: Omit<ComponentModel, 'name'> | Omit<EvaluatedComponentModel, 'name'>;
  // The editor URL when the change was made, for changes sent later. Without
  // it, the entity is taken from the current URL when the request is sent.
  editorUrl?: string;
  // The model a queued form change was made on. Not sent.
  baseModel?: ComponentModel | EvaluatedComponentModel;
  // The props a queued form change edited, if known. Not sent.
  editedProps?: string[];
};

// Component writes run one at a time: each reads the saved models, sends its
// request and applies the response before the next starts.
let componentWrites: Promise<unknown> = Promise.resolve();
const runExclusive = <T>(write: () => Promise<T>): Promise<T> => {
  const result = componentWrites.then(async () => {
    const value = await write();
    // Until the response is applied, the store holds the previous model.
    await responsesApplied;
    return value;
  });
  componentWrites = result.catch(() => {});
  return result;
};

/**
 * Returns a component instance's newest saved model on the entity an editor
 * URL edits, if known.
 *
 * It is the model of the newest successful write or loaded layout for that
 * entity, if no undo or redo cleared it since. The editor's own models can belong to another entity while
 * a layout reloads, and lack responses withheld for queued changes.
 */
export const getSavedModel = (
  uuid: string,
  editorUrl?: string,
): ComponentModel | EvaluatedComponentModel | undefined =>
  savedModels.get(instanceKey(uuid, editorUrl));

/**
 * Records the models a write saved or a layout loaded for an entity.
 */
export const recordSavedModels = (
  editorUrl: string,
  model: Record<string, any>,
) =>
  Object.entries(model).forEach(([uuid, componentModel]) =>
    savedModels.set(instanceKey(uuid, editorUrl), componentModel),
  );

/**
 * Forgets every recorded saved model, for example after undo or redo, whose
 * restored models the next layout POST saves.
 */
export const clearSavedModels = () => savedModels.clear();

/**
 * Takes the saved models of a layout's component instances on its entity.
 *
 * A full-layout write runs after queued component changes are saved; models
 * captured before then would undo them.
 */
export const withSavedModels = (
  model: Record<string, any>,
  editorUrl: string,
) =>
  Object.fromEntries(
    Object.entries(model).map(([uuid, componentModel]) => [
      uuid,
      getSavedModel(uuid, editorUrl) ?? componentModel,
    ]),
  );

// Rebases a queued form change onto the instance's newest saved model, so a
// change saved meanwhile (for example a removed image) is kept.
const rebaseOnSavedModel = (
  arg: UpdateComponentQueryArg,
): UpdateComponentQueryArg => {
  const { baseModel, model } = arg;
  const saved = getSavedModel(arg.componentInstanceUuid, arg.editorUrl);
  if (
    !baseModel ||
    !saved ||
    !isEvaluatedComponentModel(saved) ||
    !isEvaluatedComponentModel(baseModel) ||
    !isEvaluatedComponentModel(model as ComponentModel)
  ) {
    return arg;
  }
  return {
    ...arg,
    model: rebaseComponentModel(
      baseModel,
      model as EvaluatedComponentModel,
      saved,
      arg.editedProps,
    ) as EvaluatedComponentModel,
  };
};

// Paces PATCHes from usePatchComponent(). Sends through the newest
// updateComponent trigger.
let sendPatch: (arg: UpdateComponentQueryArg) => PromiseLike<unknown>;
const patchScheduler = createPatchScheduler<UpdateComponentQueryArg>((arg) =>
  runExclusive(() => Promise.resolve(sendPatch(rebaseOnSavedModel(arg)))),
);

/**
 * Runs a component write after queued changes and earlier writes.
 *
 * Read the models from the store inside `write`: no other component write
 * runs until `write` resolves and its response is applied. Drupal AJAX
 * requests wait for it.
 */
export const runComponentWrite = async <T>(
  write: () => Promise<T>,
): Promise<T> => {
  pushCanvasLayoutRequest();
  try {
    await patchScheduler.drain();
    return await runExclusive(write);
  } finally {
    popCanvasLayoutRequest();
  }
};

// Identifies a component instance on the entity an editor URL edits. The same
// instance UUID can exist on several entities, for example after duplication.
const instanceKey = (uuid: string, editorUrl = window.location.href) =>
  `${JSON.stringify(extractEntityParams(editorUrl))}/${uuid}`;

// Per instance key, the newest successful response whose model was not
// applied because a newer PATCH was queued. Applied if that PATCH fails.
const supersededModels = new Map<string, any>();

// Per instance key, the model of the newest successful response. Used to
// build a change for an entity the editor has left.
const savedModels = new Map<string, any>();

// The whole-page version that updateComponent responses set last. A newer one
// came from elsewhere, for example a layout POST.
let ownFullHtmlVersion = 0;

// The layout endpoint of each editor context.
const layoutUrls: Partial<Record<EditorFrameContext, string>> = {
  entity: 'canvas/api/v0/layout/{entity_type}/{entity_id}',
  template:
    'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}',
  pattern: 'canvas/api/v0/layout-pattern/{entity_id}',
};

// Reads and sets the whole preview of the edited entity, template or pattern,
// without saving.
const readWholePreview = async (
  dispatch: any,
  baseUrl: string,
  type: EditorFrameContext,
) => {
  const { entityType, entityId, templateBundle, templateViewMode } =
    extractEntityParams(window.location.href);
  const response = await fetch(
    baseUrl +
      replaceEntityParamsInUrl(
        layoutUrls[type] ?? '',
        entityType,
        entityId,
        templateBundle,
        templateViewMode,
      ),
    { headers: { Accept: 'application/json' } },
  );
  if (response.ok) {
    const { html, autoSaves } = await response.json();
    dispatch(setHtml(html));
    handleAutoSavesHashUpdate(dispatch, autoSaves, {
      request: { url: response.url },
    });
  }
};

// Resolves once the previous updateComponent response is applied. Responses
// are applied in turn, each after loading the assets it needs.
let responsesApplied: Promise<void> = Promise.resolve();

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
        // The editor URL the layout is for, if not the current one.
        editorUrl?: string;
      }
    >({
      query: ({ entityType, entityId, editorUrl, ...body }) => ({
        url: `canvas/api/v0/layout/${entityType}/${entityId}`,
        method: 'POST',
        body,
        editorUrl,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        pushCanvasLayoutRequest();
        try {
          const { data, meta } = await queryFulfilled;
          const { html, autoSaves } = data;
          // The editor moved to another entity since this layout was sent.
          if (isOtherEntity(arg.editorUrl)) {
            handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
            return;
          }
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
      query: ({
        type,
        editorUrl,
        baseModel: _baseModel,
        editedProps: _editedProps,
        ...body
      }) => {
        let url = layoutUrls[type] ?? '';
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
        // Only entities support a partial preview. The page's libraries let
        // the response add the libraries the updated instance needs.
        const pageLibraries = getPreviewLibraries();
        return {
          url,
          method: 'PATCH',
          body:
            type === 'entity'
              ? {
                  ...body,
                  partialPreview: true,
                  ...(pageLibraries && { pageLibraries }),
                }
              : body,
        };
      },
      async onQueryStarted(arg, { dispatch, getState, queryFulfilled }) {
        const startFullHtmlVersion = (getState() as RootState).preview
          .fullHtmlVersion;
        const startLayout = selectLayout(getState() as RootState);
        const uuid = arg.componentInstanceUuid;
        const key = instanceKey(uuid, arg.editorUrl);
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
        // Responses are applied in the order their requests started. The
        // scheduler waits for this before sending the next PATCH.
        const previousResponseApplied = responsesApplied;
        let markApplied = () => {};
        responsesApplied = new Promise((resolve) => (markApplied = resolve));
        // Force any ajax calls to wait until this response is applied.
        pushCanvasLayoutRequest();
        try {
          let result: Awaited<typeof queryFulfilled> | undefined;
          try {
            result = await queryFulfilled;
          } catch {
            // If the request fails (e.g. the server rejects an invalid field
            // value), the lock is still released below, so that subsequent
            // Drupal AJAX calls are not permanently blocked.
            // @see https://www.drupal.org/project/canvas/issues/3579026
          }
          await previousResponseApplied;
          if (!result) {
            // The instance's model from a successful response this one
            // superseded is now its newest saved one.
            const superseded = supersededModels.get(key);
            if (superseded && !patchScheduler.has(key)) {
              supersededModels.delete(key);
              // Not if the editor moved to another entity since.
              if (!isOtherEntity(arg.editorUrl)) {
                applyModel({ model: { [uuid]: superseded } });
                // Its draft is the saved one: publishing needs its hash.
                dispatch(
                  pendingChangesApi.util.invalidateTags([
                    { type: 'PendingChanges', id: 'LIST' },
                  ]),
                );
              }
            }
            return;
          }
          const { data, meta } = result as { data: any; meta: any };
          const { model, autoSaves } = data;
          const instanceModel = model?.[uuid];
          if ('component' in data) {
            if (instanceModel) {
              savedModels.set(key, instanceModel);
            }
          } else if (model) {
            // A whole-page response can also update other instances, for
            // example to their component's latest version.
            recordSavedModels(arg.editorUrl ?? window.location.href, model);
          }
          // The editor moved to another entity since this change was made.
          if (isOtherEntity(arg.editorUrl)) {
            // This model is newer than one kept for if a later PATCH fails.
            supersededModels.delete(key);
            handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
            return;
          }
          if ('assets' in data && data.assets) {
            await loadPreviewAssets(data.assets);
            if (isOtherEntity(arg.editorUrl)) {
              handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
              return;
            }
          }
          // A queued PATCH for this instance will bring a newer model. Applying
          // this one would undo what was typed since this one was sent.
          const isOutdated = patchScheduler.has(key);
          if (!isOutdated) {
            dispatch(
              pendingChangesApi.util.invalidateTags([
                { type: 'PendingChanges', id: 'LIST' },
              ]),
            );
          }
          const { preview, configuration } = getState() as RootState;
          // A whole page set from elsewhere since this request started, for
          // example by a layout POST, is newer than this response: its markup
          // and models would undo it. Read the current whole page instead.
          // The read does not count as this endpoint's own page, so later
          // responses that started before it are read again too.
          if (
            preview.fullHtmlVersion !== startFullHtmlVersion &&
            preview.fullHtmlVersion !== ownFullHtmlVersion
          ) {
            await readWholePreview(dispatch, configuration.baseUrl, arg.type);
            return;
          }
          // Clear any stale snapshot (e.g. from a prior language/template preview)
          // so selectPreviewHtml returns this fresh editor html instead of the
          // preview snapshot, which selectPreviewHtml prefers while set.
          dispatch(setSnapshotHTML(''));
          dispatch(setSnapshotTitle(''));
          const state = getState() as RootState;
          if ('component' in data) {
            const html = spliceComponentHtml(
              state.preview.html,
              data.component,
            );
            if (html === null) {
              // The current preview lacks the instance: read the whole page,
              // rather than saving the change again over newer ones.
              await readWholePreview(
                dispatch,
                state.configuration.baseUrl,
                arg.type,
              );
            } else {
              dispatch(setPartialHtml({ html, assets: data.assets }));
            }
          } else {
            dispatch(setHtml(data.html));
          }
          ownFullHtmlVersion = (getState() as RootState).preview
            .fullHtmlVersion;
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
          if (isOutdated) {
            if (instanceModel) {
              supersededModels.set(key, instanceModel);
            }
          } else {
            supersededModels.delete(key);
          }
          if ('component' in data) {
            if (!isOutdated) {
              applyModel({ model });
            }
            return;
          }
          // A whole-page response can also update other instances, for example
          // to their component's latest version. An outdated instance keeps
          // its current model.
          const current = selectModel(getState() as RootState);
          const serverModels: Record<string, any> = { ...model };
          if (isOutdated) {
            delete serverModels[uuid];
          }
          if (selectLayout(getState() as RootState) === startLayout) {
            applyModel({
              layout: data.layout,
              model: {
                ...serverModels,
                ...(isOutdated && current[uuid] && { [uuid]: current[uuid] }),
              },
            });
          } else {
            // A structural edit since this request started, such as a move or
            // a delete, waits for a layout POST. Keep it, and update only the
            // instances it kept.
            applyModel({
              model: Object.fromEntries(
                Object.entries(serverModels).filter(([id]) => id in current),
              ),
            });
          }
        } finally {
          markApplied();
          // Tell ajax calls they're good to go regardless of success/failure.
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
    editedProps?: string[],
    delay?: number,
  ) => {
    const {
      selectedComponent,
      selectedComponentType,
      version,
      editorFrameContext,
    } = inputUIData;

    // A change replacing a queued one also carries the queued one's edits.
    const queued = patchScheduler.get(instanceKey(selectedComponent));
    const arg = {
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model,
      editorUrl: window.location.href,
      baseModel: inputUIData.model?.[selectedComponent],
      editedProps:
        editedProps && (!queued || queued.editedProps)
          ? [...new Set([...(queued?.editedProps ?? []), ...editedProps])]
          : undefined,
    };

    // Prevent duplicate requests
    const stringBody = JSON.stringify(arg);
    if (stringBody === lastBody) {
      // Return a resolved promise to mimic successful completion
      return Promise.resolve({ data: undefined }) as any;
    }
    lastBody = stringBody;
    sendPatch = updateComponent;
    patchScheduler.schedule(instanceKey(selectedComponent), arg, false, delay);
  };
};

/**
 * Patches a component instance with a model built from its saved model.
 *
 * The model is built as a component write, after queued changes and earlier
 * writes are saved, so it builds on them and nothing is saved in between.
 *
 * @param options - Options for useUpdateComponentMutation().
 */
export const usePatchComponentModel = (
  options?: Parameters<typeof useUpdateComponentMutation>[0],
) => {
  const [updateComponent] = useUpdateComponentMutation(options);

  return (
    inputUIData: InputUIData,
    buildModel: (
      savedModel: EvaluatedComponentModel,
    ) => UpdateComponentQueryArg['model'] | null,
  ) => {
    const {
      selectedComponent,
      selectedComponentType,
      version,
      editorFrameContext,
    } = inputUIData;
    const editorUrl = window.location.href;
    return runComponentWrite(async () => {
      // If the editor moved to another entity meanwhile, the store holds that
      // entity's models instead.
      const savedModel =
        getSavedModel(selectedComponent, editorUrl) ??
        inputUIData.model?.[selectedComponent];
      if (!savedModel || !isEvaluatedComponentModel(savedModel)) {
        return;
      }
      const model = buildModel(savedModel);
      if (model) {
        await updateComponent({
          type: editorFrameContext,
          componentInstanceUuid: selectedComponent,
          componentType: `${selectedComponentType}@${version}`,
          model,
          editorUrl,
        });
      }
    });
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
  const patchComponentModel = usePatchComponentModel();

  return (
    inputUIData: InputUIData,
    propName: string,
    sourceValue: PropSource | PropSource['value'],
    resolvedValue: ResolvedValues[string],
  ) =>
    patchComponentModel(inputUIData, (savedModel) => ({
      source: { ...savedModel.source, [propName]: sourceValue },
      resolved: { ...savedModel.resolved, [propName]: resolvedValue },
    }));
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
  // The editor URL the layout is for. Defaults to the current one.
  editorUrl?: string;
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
    requestArg: PostPreviewArg,
  ): Promise<PostPreviewResult> => {
    const arg = {
      ...requestArg,
      editorUrl: requestArg.editorUrl ?? window.location.href,
    };
    if (activePreviewRequest) {
      pendingPreviewArg = arg;
      await activePreviewRequest.catch(() => {});
      if (pendingPreviewArg !== arg) {
        // Superseded by a newer request - never resolve
        return new Promise(() => {});
      }
      pendingPreviewArg = null;
    }

    activePreviewRequest = runComponentWrite(async () => {
      const model = withSavedModels(arg.model, arg.editorUrl);
      const result = await postPreview({ ...arg, model }).unwrap();
      recordSavedModels(arg.editorUrl, model);
      return result;
    });
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
