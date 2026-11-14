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
export const { setLatestError, setMaintenanceMessage } =
  queryErrorSlice.actions;
export const { selectLatestError, selectMaintenanceMessage } =
  queryErrorSlice.selectors;
