import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';

export interface queryError {
  status: string;
  errors?: any;
  message: string;
}

export interface queryErrorSliceState {
  latestError: queryError | undefined;
  // The site maintenance message, set when a request gets an HTTP 503.
  maintenanceMessage: string | undefined;
}

export const initialState: queryErrorSliceState = {
  latestError: undefined,
  maintenanceMessage: undefined,
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
    // Cleared when the user leaves the editor, so an old 403 or 409 does not
    // block the next entity.
    clearLatestError: create.reducer((state) => {
      state.latestError = undefined;
    }),
    setMaintenanceMessage: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.maintenanceMessage = action.payload;
      },
    ),
  }),
  selectors: {
    selectLatestError: (state): queryError | undefined => {
      return state.latestError;
    },
    selectMaintenanceMessage: (state): string | undefined => {
      return state.maintenanceMessage;
    },
  },
});
export const { setLatestError, clearLatestError, setMaintenanceMessage } =
  queryErrorSlice.actions;
export const { selectLatestError, selectMaintenanceMessage } =
  queryErrorSlice.selectors;
