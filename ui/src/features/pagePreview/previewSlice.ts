import { createSlice } from '@reduxjs/toolkit';

import type { CaseReducer, PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

export interface PreviewState {
  html: string;
  snapshotHTML: string;
  snapshotTitle: string;
  backgroundUpdate: boolean;
}

export const initialState: PreviewState = {
  html: '',
  snapshotHTML: '',
  snapshotTitle: '',
  backgroundUpdate: false,
};

const setHtmlReducer: CaseReducer<PreviewState, PayloadAction<string>> = (
  state,
  action,
) => ({
  ...state,
  html: action.payload,
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
    setHtml: setHtmlReducer,
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
export const selectPreviewBackgroundUpdate = (state: RootState) =>
  state.preview.backgroundUpdate;
export const selectSnapshotTitle = (state: RootState) =>
  state.preview.snapshotTitle;
