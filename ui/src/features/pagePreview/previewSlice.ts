import { createSelector, createSlice } from '@reduxjs/toolkit';

import { injectPreviewAssets } from '@/features/layout/preview/previewAssets';

import type { CaseReducer, PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';
import type { PreviewAssets } from '@/features/layout/preview/previewAssets';

export interface PreviewState {
  html: string;
  // Assets partial previews added to the live preview since `html` was last
  // set to a whole page.
  addedAssets: PreviewAssets[];
  snapshotHTML: string;
  snapshotTitle: string;
  backgroundUpdate: boolean;
}

export const initialState: PreviewState = {
  html: '',
  addedAssets: [],
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
  addedAssets: [],
});

// Sets `html` with one component instance's markup replaced, keeping the
// assets added before.
const setPartialHtmlReducer: CaseReducer<
  PreviewState,
  PayloadAction<{ html: string; assets?: PreviewAssets }>
> = (state, { payload: { html, assets } }) => ({
  ...state,
  html,
  addedAssets: assets ? [...state.addedAssets, assets] : state.addedAssets,
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
    setPartialHtml: setPartialHtmlReducer,
    setSnapshotHTML: setSnapshotHTMLReducer,
    setSnapshotTitle: setSnapshotTitleReducer,
    setPreviewBackgroundUpdate: setPreviewBackgroundUpdateReducer,
  },
});

// Action creators are generated for each case reducer function.
export const {
  setHtml,
  setPartialHtml,
  setSnapshotHTML,
  setSnapshotTitle,
  setPreviewBackgroundUpdate,
} = previewSlice.actions;

export const selectPreviewHtml = (state: RootState) =>
  state.preview.snapshotHTML || state.preview.html;
export const selectAddedPreviewAssets = (state: RootState) =>
  state.preview.snapshotHTML ? [] : state.preview.addedAssets;
// The preview HTML with the assets partial previews added, for a new frame.
export const selectPreviewDocument = createSelector(
  [selectPreviewHtml, selectAddedPreviewAssets],
  injectPreviewAssets,
);
export const selectPreviewBackgroundUpdate = (state: RootState) =>
  state.preview.backgroundUpdate;
export const selectSnapshotTitle = (state: RootState) =>
  state.preview.snapshotTitle;
