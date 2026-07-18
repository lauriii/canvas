import { getCanvasSettings } from '@/utils/drupal-globals';

/**
 * All preview/persistence cadence values live here so they can be tuned
 * against the Server-Timing + performance-mark instrumentation in one place.
 */
export const PREVIEW_CADENCE = {
  /**
   * Input debounce for component prop form edits (ms).
   *
   * With rendering pure (no auto-save write per render request) this only
   * paces render requests; superseded renders are aborted.
   */
  INPUT_DEBOUNCE: 150,
  /**
   * Delay before the authoritative background request on the code-component
   * island fast path (ms). The user already sees the optimistic island
   * update; this only paces the authoritative render.
   */
  ISLAND_BACKGROUND_UPDATE_DELAY: 400,
  /**
   * Trailing debounce for the persist-only auto-save POST (ms). Flushed on
   * blur, pagehide, and tab-hide so half-finished work survives an exit.
   */
  AUTOSAVE_DEBOUNCE: 2000,
} as const;

export interface PreviewPerformanceFlags {
  /** Apply PATCH/render responses as in-place subtree swaps. */
  subtreePatching: boolean;
  /** Apply move/delete/duplicate/sort to the preview DOM before any network. */
  optimisticOps: boolean;
  /** Persist auto-save on its own debounced cadence instead of per render. */
  decoupledAutoSave: boolean;
  /** Use the partial render endpoint for bounded prop edits and inserts. */
  partialRender: boolean;
  /** Declare the not-edited tree frozen on preview/persist requests. */
  frozenTrees: boolean;
}

const defaults: PreviewPerformanceFlags = {
  subtreePatching: true,
  optimisticOps: true,
  decoupledAutoSave: true,
  partialRender: true,
  frozenTrees: true,
};

/**
 * Per-phase rollback switches; override via drupalSettings.canvas
 * .previewPerformance. The full-document render path is never removed, so
 * turning any flag off falls back to the previous behavior.
 */
export const getPreviewPerformanceFlags = (): PreviewPerformanceFlags => ({
  ...defaults,
  ...(getCanvasSettings()?.previewPerformance ?? {}),
});
