import { describe, expect, it } from 'vitest';

import {
  isPreviewEditedSince,
  notePreviewEdit,
  previewEditCount,
} from '@/features/layout/preview/previewEdits';
import {
  initialState,
  previewSlice,
  setHtml,
} from '@/features/pagePreview/previewSlice';

describe('setHtml', () => {
  it('records the edits made to the preview before the html was requested', () => {
    const editsAtRequest = 3;
    expect(
      previewSlice.reducer(initialState, setHtml('<p>A</p>', editsAtRequest))
        .htmlEditsAtRequest,
    ).toBe(3);
    // Without a count, html is as new as the edits made so far.
    notePreviewEdit();
    const state = previewSlice.reducer(initialState, setHtml('<p>B</p>'));
    expect(state.htmlEditsAtRequest).toBeGreaterThan(0);
  });
});

describe('isPreviewEditedSince', () => {
  // IFrameSwapper shows html, in place or by a swap, only if this is false.
  it('rejects html requested before a preview edit', () => {
    const before = previewSlice.reducer(
      initialState,
      setHtml('<p>A</p>', previewEditCount()),
    );
    // For example, a prop value typed into a canvas-island.
    notePreviewEdit();
    expect(isPreviewEditedSince(before.htmlEditsAtRequest)).toBe(true);
  });

  it('accepts html requested after a preview edit', () => {
    notePreviewEdit();
    const after = previewSlice.reducer(
      initialState,
      setHtml('<p>AB</p>', previewEditCount()),
    );
    expect(isPreviewEditedSince(after.htmlEditsAtRequest)).toBe(false);
  });

  it('rejects html requested before an edit when it arrives last', () => {
    const editsAtFirstRequest = previewEditCount();
    notePreviewEdit();
    const editsAtSecondRequest = previewEditCount();
    let state = previewSlice.reducer(
      initialState,
      setHtml('<p>AB</p>', editsAtSecondRequest),
    );
    expect(isPreviewEditedSince(state.htmlEditsAtRequest)).toBe(false);
    state = previewSlice.reducer(
      state,
      setHtml('<p>A</p>', editsAtFirstRequest),
    );
    expect(isPreviewEditedSince(state.htmlEditsAtRequest)).toBe(true);
  });
});
