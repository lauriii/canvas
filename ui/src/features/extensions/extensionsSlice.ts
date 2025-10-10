import { createAppSlice } from '@/app/createAppSlice';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { ActiveExtension, Extension } from '@/types/Extensions';

export interface ExtensionsSliceState {
  activeExtension: ActiveExtension;
  subscriptions: string[];
}

const initialState: ExtensionsSliceState = {
  activeExtension: null,
  subscriptions: [],
};

export const extensionsSlice = createAppSlice({
  name: 'extensions',
  initialState,
  reducers: (create) => ({
    setActiveExtension: create.reducer(
      (state, action: PayloadAction<Extension>) => {
        state.activeExtension = action.payload;
        state.subscriptions = [];
      },
    ),
    unsetActiveExtension: create.reducer((state) => {
      state.activeExtension = null;
      state.subscriptions = [];
    }),
    setSubscriptions: create.reducer(
      (state, action: PayloadAction<string[]>) => {
        state.subscriptions = action.payload;
      },
    ),
  }),
  selectors: {
    selectActiveExtension: (state): ActiveExtension => {
      return state.activeExtension;
    },
    selectSubscriptions: (state): string[] => {
      return state.subscriptions;
    },
  },
});

export const extensionsReducer = extensionsSlice.reducer;

export const { setActiveExtension, unsetActiveExtension, setSubscriptions } =
  extensionsSlice.actions;
export const { selectActiveExtension, selectSubscriptions } =
  extensionsSlice.selectors;
