import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';

export interface queryError {
  status: string;
  errors?: any;
  message: string;
}

export interface queryErrorSliceState {
  latestError: queryError | undefined;
}

export const initialState: queryErrorSliceState = {
  latestError: undefined,
};

export const queryErrorSlice = createSlice({
  name: 'queryError',
  initialState,
  reducers: (create) => ({
    setLatestError: create.reducer(
      (state, action: PayloadAction<queryError>) => {
        state.latestError = action.payload;
      },
    ),
    // The error belongs to what was open when it happened; consumers clear it
    // when that context is left (e.g. the editor on route change), so a stale
    // 403/409 does not block the next entity.
    clearLatestError: create.reducer((state) => {
      state.latestError = undefined;
    }),
  }),
  selectors: {
    selectLatestError: (state): queryError | undefined => {
      return state.latestError;
    },
  },
});
export const { setLatestError, clearLatestError } = queryErrorSlice.actions;
export const { selectLatestError } = queryErrorSlice.selectors;
