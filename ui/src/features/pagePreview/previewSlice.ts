import type { RootState } from '@/app/store';
import type { CaseReducer, PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';

export interface PreviewState {
  html: string;
}

export const initialState: PreviewState = {
  html: '',
};

const setHtmlReducer: CaseReducer<PreviewState, PayloadAction<string>> = (
  state,
  action,
) => ({
  html: action.payload,
});

export const previewSlice = createSlice({
  name: 'preview',
  initialState,
  reducers: {
    setHtml: setHtmlReducer,
  },
});

// Action creators are generated for each case reducer function.
export const { setHtml } = previewSlice.actions;

export const selectPreviewHtml = (state: RootState) => state.preview.html;
