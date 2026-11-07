import {
  isEvaluatedComponentModel,
  selectLayout,
  selectModel,
  syncPropSourcesToResolvedValues,
} from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import {
  inlineTextCommitSettled,
  inlineTextCommitStarted,
  refreshComponentForm,
  selectEditorFrameContext,
  selectSelectedComponentUuid,
  selectTextEditSession,
} from '@/features/ui/uiSlice';
import { extractEntityParams } from '@/services/baseQuery';
import { previewApi } from '@/services/preview';

import type { AppThunk } from '@/app/store';
import type { ComponentsList } from '@/types/Component';

export type InlineTextCommitResult =
  | { ok: true }
  | { ok: false; reason: 'navigated' | 'failed'; status?: number | string };

/** Identifies the entity being edited, from the current route. */
export const currentEditTarget = () =>
  JSON.stringify(extractEntityParams(window.location.pathname));

// Commits run one at a time. Prop updates are server-first, so each one must
// see the model the previous one produced, or a quick second edit to another
// prop of the same component would send, and so restore, the stale value of
// the first.
let queue: Promise<unknown> = Promise.resolve();

// Values sent but not yet in the model, keyed by `{component}/{prop}`. A
// session opened on such a prop starts from this value, not the model's.
const pendingValues = new Map<string, string>();

/** The value of a prop whose commit is still in flight, if any. */
export const getPendingInlineValue = (
  componentUuid: string,
  propName: string,
) => pendingValues.get(`${componentUuid}/${propName}`);

// The component whose sidebar form missed a refresh because text was being
// edited at the time.
let formRefreshDeferredFor: string | null = null;

/**
 * Re-renders the component instance form with the model's values.
 *
 * The form keeps its own copy of the values. While text is being edited, the
 * refresh waits: it would drop the sidebar's "editing on canvas" state, and it
 * runs when that session ends.
 */
export const refreshComponentFormFor =
  (componentUuid: string): AppThunk =>
  (dispatch, getState) => {
    if (selectSelectedComponentUuid(getState()) !== componentUuid) {
      return;
    }
    if (selectTextEditSession(getState())) {
      formRefreshDeferredFor = componentUuid;
      return;
    }
    formRefreshDeferredFor = null;
    dispatch(refreshComponentForm());
  };

/** Runs a form refresh that waited for a session to end. */
export const runDeferredFormRefresh = (): AppThunk => (dispatch) => {
  if (formRefreshDeferredFor) {
    dispatch(refreshComponentFormFor(formRefreshDeferredFor));
  }
};

/**
 * Writes text edited in place to one component instance's props.
 *
 * Sends the same request as the component instance form, so validation,
 * auto-save, translation and undo behave as for a sidebar edit. The result
 * reports failure instead of throwing, so the caller can keep the text.
 *
 * @param componentUuid
 *   The component instance.
 * @param values
 *   New resolved values, keyed by prop name.
 * @param target
 *   The entity the edit was made on, from currentEditTarget(). Request URLs
 *   follow the current route, so a commit after navigating away is refused
 *   rather than written to another entity.
 */
export const commitInlineText =
  (
    componentUuid: string,
    values: Record<string, string>,
    target: string,
  ): AppThunk<Promise<InlineTextCommitResult>> =>
  (dispatch, getState) => {
    dispatch(inlineTextCommitStarted());
    const keys = Object.entries(values).map(([propName, value]) => {
      const key = `${componentUuid}/${propName}`;
      pendingValues.set(key, value);
      return [key, value];
    });
    const run = async (): Promise<InlineTextCommitResult> => {
      if (currentEditTarget() !== target) {
        return { ok: false, reason: 'navigated' };
      }
      const state = getState();
      const components = state.componentAndLayoutApi?.queries?.[
        'getComponents(undefined)'
      ]?.data as ComponentsList | undefined;
      const model = selectModel(state)[componentUuid];
      const node = findComponentByUuid(selectLayout(state), componentUuid);
      if (!components || !model || !node) {
        return { ok: false, reason: 'failed' };
      }
      const [componentType] = node.type.split('@');
      const resolved = { ...model.resolved, ...values };
      const componentMetadata = components[componentType];
      const result = await dispatch(
        previewApi.endpoints.updateComponent.initiate(
          {
            type: selectEditorFrameContext(state),
            componentInstanceUuid: componentUuid,
            componentType: node.type,
            model:
              isEvaluatedComponentModel(model) && componentMetadata
                ? {
                    source: syncPropSourcesToResolvedValues(
                      model.source,
                      componentMetadata,
                      resolved,
                    ),
                    resolved,
                  }
                : { ...model, resolved },
          },
          { fixedCacheKey: componentUuid },
        ),
      );
      if ('error' in result) {
        return {
          ok: false,
          reason: 'failed',
          status: (result.error as { status?: number | string })?.status,
        };
      }
      dispatch(refreshComponentFormFor(componentUuid));
      return { ok: true };
    };
    const commit = queue.then(run, run);
    queue = commit.catch(() => undefined);
    return commit
      .catch((): InlineTextCommitResult => ({ ok: false, reason: 'failed' }))
      .finally(() => {
        // A later commit of the same prop owns the entry once it is sent.
        keys.forEach(([key, value]) => {
          if (pendingValues.get(key) === value) {
            pendingValues.delete(key);
          }
        });
        dispatch(inlineTextCommitSettled());
        // A refresh deferred by a session runs once nothing is being edited,
        // whatever the outcome of this commit.
        dispatch(runDeferredFormRefresh());
      });
  };
