import { describe, expect, it } from 'vitest';

import { notePreviewEdit } from '@/features/layout/preview/previewEdits';
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
