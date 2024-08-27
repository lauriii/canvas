import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

export interface AppConfiguration {
  baseUrl: string;
  entityType: string;
  entity: string;
}

export const initialState: AppConfiguration = {
  baseUrl: '/',
  entityType: 'none',
  entity: 'none',
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

export default configurationSlice.reducer;
export const selectBaseUrl = (state: RootState) => state.configuration.baseUrl;

export const selectEntityType = (state: RootState) =>
  state.configuration.entityType;
export const selectEntityId = (state: RootState) => state.configuration.entity;
