import { createSlice } from '@reduxjs/toolkit';

import { previewEditCount } from '@/features/layout/preview/previewEdits';

import type { CaseReducer, PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

export interface PreviewState {
  html: string;
  // The edits made to the shown preview before the html was requested.
  htmlEditsAtRequest: number;
  // The most edits made before a render built from the layout and model in
  // the store was requested, among those received.
  renderedEdits: number;
  snapshotHTML: string;
  snapshotTitle: string;
  backgroundUpdate: boolean;
}

export const initialState: PreviewState = {
  html: '',
  htmlEditsAtRequest: 0,
  renderedEdits: 0,
  snapshotHTML: '',
  snapshotTitle: '',
  backgroundUpdate: false,
};

const setHtmlReducer: CaseReducer<
  PreviewState,
  PayloadAction<string, string, { editsAtRequest: number; patch: boolean }>
> = (state, action) => ({
  ...state,
  html: action.payload,
  htmlEditsAtRequest: action.meta.editsAtRequest,
  renderedEdits: action.meta.patch
    ? state.renderedEdits
    : Math.max(state.renderedEdits, action.meta.editsAtRequest),
});

const setSnapshotHTMLReducer: CaseReducer<
  PreviewState,
  PayloadAction<string>
> = (state, action) => ({ ...state, snapshotHTML: action.payload });

const setSnapshotTitleReducer: CaseReducer<
  PreviewState,
  PayloadAction<string>
> = (state, action) => ({ ...state, snapshotTitle: action.payload });

const setPreviewBackgroundUpdateReducer: CaseReducer<
  PreviewState,
  PayloadAction<boolean>
> = (state, action) => ({ ...state, backgroundUpdate: action.payload });

export const previewSlice = createSlice({
  name: 'preview',
  initialState,
  reducers: {
    setHtml: {
      reducer: setHtmlReducer,
      /**
       * @param html
       *   The preview html.
       * @param editsAtRequest
       *   The edits made to the shown preview before the html was requested
       *   (see previewEdits.ts). Defaults to all of them.
       * @param patch
       *   Whether the html is from a component update (PATCH) response. Its
       *   request isn't built from the store, and PATCH responses for typing
       *   can arrive out of order, so it doesn't count toward renderedEdits.
       */
      prepare: (
        html: string,
        editsAtRequest = previewEditCount(),
        patch = false,
      ) => ({
        payload: html,
        meta: { editsAtRequest, patch },
      }),
    },
    setSnapshotHTML: setSnapshotHTMLReducer,
    setSnapshotTitle: setSnapshotTitleReducer,
    setPreviewBackgroundUpdate: setPreviewBackgroundUpdateReducer,
  },
});

// Action creators are generated for each case reducer function.
export const {
  setHtml,
  setSnapshotHTML,
  setSnapshotTitle,
  setPreviewBackgroundUpdate,
} = previewSlice.actions;

export const selectPreviewHtml = (state: RootState) =>
  state.preview.snapshotHTML || state.preview.html;
// A snapshot is shown as it is, whatever has been edited since.
export const selectPreviewHtmlEditsAtRequest = (state: RootState) =>
  state.preview.snapshotHTML ? Infinity : state.preview.htmlEditsAtRequest;
export const selectPreviewBackgroundUpdate = (state: RootState) =>
  state.preview.backgroundUpdate;
export const selectSnapshotTitle = (state: RootState) =>
  state.preview.snapshotTitle;
