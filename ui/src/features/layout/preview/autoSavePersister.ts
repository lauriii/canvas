/**
 * Debounced, decoupled auto-save persistence.
 *
 * Preview paint never waits on persistence: edits paint optimistically or
 * through the partial render endpoint, while this module persists the full
 * document state on a trailing debounce, flushed on exit events (window
 * blur, pagehide, tab-hide) so half-finished work survives an abrupt exit.
 *
 * A persist failure (e.g. a 409 conflict) falls back to one full preview
 * render, which re-runs auto-save validation on the server and surfaces the
 * conflict through the existing error flow.
 */
import {
  selectLayout,
  selectModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  getFrozenTreeDeclaration,
  isAnythingDirty,
  markPersisted,
  snapshotEditVersions,
} from '@/features/layout/preview/previewTreeState';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { previewApi } from '@/services/preview';
import { enqueueLayoutWrite } from '@/services/previewWriteChain';
import { PREVIEW_CADENCE } from '@/utils/previewCadence';
import { previewPerfMark } from '@/utils/previewPerf';

import { getConsolidatedModelOverrides } from './partialRender';

import type { AppDispatch, RootState } from '@/app/store';
import type { PersistLayoutQueryArg } from '@/services/preview';

type StoreLike = {
  dispatch: AppDispatch;
  getState: () => RootState;
};

let timer: ReturnType<typeof setTimeout> | null = null;
let boundStore: StoreLike | null = null;

/**
 * Schedules a trailing-debounce persist of the current document state.
 */
export function schedulePersist(store: StoreLike): void {
  boundStore = store;
  if (timer) {
    clearTimeout(timer);
  }
  timer = setTimeout(() => {
    timer = null;
    void flushPersist();
  }, PREVIEW_CADENCE.AUTOSAVE_DEBOUNCE);
}

/** Drops any pending persist (e.g. a full-document POST just persisted). */
export function cancelScheduledPersist(): void {
  if (timer) {
    clearTimeout(timer);
    timer = null;
  }
}

/**
 * Persists now if anything is dirty. Serialized on the shared write chain.
 */
export function flushPersist(): Promise<void> {
  const store = boundStore;
  cancelScheduledPersist();
  if (!store || !isAnythingDirty()) {
    return Promise.resolve();
  }
  const snapshot = snapshotEditVersions();
  return enqueueLayoutWrite(async () => {
    const state = store.getState();
    const body: PersistLayoutQueryArg = {
      layout: selectLayout(state),
      // Prop edits still waiting for their render-endpoint echo are overlaid
      // so the persist carries the newest inputs.
      model: {
        ...selectModel(state),
        ...getConsolidatedModelOverrides(),
      },
      entity_form_fields: selectPageData(state),
    };
    const frozen = getFrozenTreeDeclaration();
    if (frozen) {
      body.frozen = frozen;
    }
    previewPerfMark('persist-flush', { frozen });
    try {
      await store
        .dispatch(previewApi.endpoints.persistLayout.initiate(body))
        .unwrap();
      markPersisted(snapshot);
    } catch {
      // Fall back to one full render: it re-runs the same validation and
      // surfaces conflicts (e.g. 409) through the existing error boundary.
      store.dispatch(setUpdatePreview(true));
    }
  });
}

const onVisibilityChange = (): void => {
  if (document.visibilityState === 'hidden') {
    void flushPersist();
  }
};
const onExit = (): void => {
  void flushPersist();
};

/**
 * Registers the exit-event flush listeners for the editor's lifetime.
 *
 * @returns A cleanup callback.
 */
export function initAutoSavePersister(store: StoreLike): () => void {
  boundStore = store;
  window.addEventListener('blur', onExit);
  window.addEventListener('pagehide', onExit);
  document.addEventListener('visibilitychange', onVisibilityChange);
  return () => {
    window.removeEventListener('blur', onExit);
    window.removeEventListener('pagehide', onExit);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    cancelScheduledPersist();
  };
}
