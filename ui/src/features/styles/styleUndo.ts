import { setComponentStyles } from '@/features/layout/layoutModelSlice';

import type { Action } from '@reduxjs/toolkit';
import type { SetComponentStylesPayload } from '@/features/layout/layoutModelSlice';

/** Changes to the same style on the same instance within this time merge. */
export const STYLE_UNDO_MERGE_MS = 800;

let last: { key: string; at: number; group: string } | null = null;
let sequence = 0;

/**
 * Decides whether a style change merges into the previous undo step.
 *
 * Returns the redux-undo group for the action, and whether it merges with the
 * previous one, so the undo stack in the ui slice skips it too.
 */
export function styleUndoGroup(
  action: Action,
  now: number = Date.now(),
): { group: string; merges: boolean } | null {
  if (action.type !== setComponentStyles.type) {
    return null;
  }
  const { mergeKey } = (
    action as Action & { payload: SetComponentStylesPayload }
  ).payload;
  if (
    mergeKey &&
    last &&
    last.key === mergeKey &&
    now - last.at < STYLE_UNDO_MERGE_MS
  ) {
    last.at = now;
    return { group: last.group, merges: true };
  }
  sequence += 1;
  last = mergeKey
    ? { key: mergeKey, at: now, group: `styles:${sequence}` }
    : null;
  return { group: `styles:${sequence}`, merges: false };
}

/** Resets the merge state, for tests. */
export function resetStyleUndoGroup(): void {
  last = null;
}
