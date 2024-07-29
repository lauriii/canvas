import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

export interface AppConfiguration {
  baseUrl: string;
  // Here we will be able to add extra parameters such as e.g. entity-type and
  // ID.
}

export const initialState: AppConfiguration = {
  baseUrl: '/',
};

export const configurationSlice = createSlice({
  name: 'configuration',
  initialState,
  reducers: (create) => ({
    setConfiguration: create.reducer(
      (state, action: PayloadAction<AppConfiguration>) => ({
        ...state,
        ...action.payload,
      }),
    ),
  }),
});

export const { setConfiguration } = configurationSlice.actions;

export const selectBaseUrl = (state: RootState) => state.configuration.baseUrl;
