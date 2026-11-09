import { createSlice } from '@reduxjs/toolkit';

import { previewEditCount } from '@/features/layout/preview/previewEdits';

import type { CaseReducer, PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

export interface PreviewState {
  html: string;
  // The edits made to the shown preview before the html was requested.
  htmlEditsAtRequest: number;
  snapshotHTML: string;
  snapshotTitle: string;
  backgroundUpdate: boolean;
}

export const initialState: PreviewState = {
  html: '',
  htmlEditsAtRequest: 0,
  snapshotHTML: '',
  snapshotTitle: '',
  backgroundUpdate: false,
};

const setHtmlReducer: CaseReducer<
  PreviewState,
  PayloadAction<string, string, { editsAtRequest: number }>
> = (state, action) => ({
  ...state,
  html: action.payload,
  htmlEditsAtRequest: action.meta.editsAtRequest,
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
       */
      prepare: (html: string, editsAtRequest = previewEditCount()) => ({
        payload: html,
        meta: { editsAtRequest },
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
