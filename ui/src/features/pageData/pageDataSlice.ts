import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { StateWithHistory } from 'redux-undo';

interface Values {
  [key: string]: any;
}

export interface PageDataState extends Values {}

const initialState: PageDataState = {};

export interface PageDataOwner {
  entityType: string;
  entityId: string;
}

interface InitialPageData {
  values: Values;
  owner: PageDataOwner | null;
}

export interface StateWithHistoryWrapper {
  pageData: StateWithHistory<PageDataState>;
}

export const pageDataSlice = createSlice({
  name: 'pageData',
  initialState,
  reducers: (create) => ({
    resetPageData: create.reducer(() => initialState),
    setPageData: create.reducer((state, action: PayloadAction<Values>) => {
      return {
        ...state,
        ...action.payload,
      };
    }),
    // Initial Page data is a complete snapshot. Replace the current state
    // without creating an undo/redo action.
    setInitialPageData: create.reducer(
      (_state, action: PayloadAction<InitialPageData>) => action.payload.values,
    ),
    externalUpdateComplete: create.reducer(
      (state, action: PayloadAction<string>) => {
        const { externalUpdates } = state;
        if (externalUpdates && action.payload) {
          const updatedExternalUpdates = externalUpdates.filter(
            (field: string) => {
              return action.payload !== field;
            },
          );
          return {
            ...state,
            externalUpdates: updatedExternalUpdates,
          };
        }
        return state;
      },
    ),
    updatePageDataExternally: create.reducer(
      (state, action: PayloadAction<Values>) => {
        const externalUpdates = state?.externalUpdates || [];
        return {
          ...state,
          ...action.payload,
          externalUpdates: [...externalUpdates, ...Object.keys(action.payload)],
        };
      },
    ),
  }),
});

export const {
  resetPageData,
  setPageData,
  setInitialPageData,
  updatePageDataExternally,
  externalUpdateComplete,
} = pageDataSlice.actions;

export const pageDataReducer = pageDataSlice.reducer;

// Keep ownership out of the editable values submitted to Drupal and tracked
// by undo history.
export const pageDataOwnerSlice = createSlice({
  name: 'pageDataOwner',
  initialState: null as PageDataOwner | null,
  reducers: {},
  extraReducers: (builder) => {
    builder
      .addCase(setInitialPageData, (_state, action) => action.payload.owner)
      .addCase(resetPageData, () => null);
  },
});

export const pageDataOwnerReducer = pageDataOwnerSlice.reducer;

export const selectPageData = (state: StateWithHistoryWrapper) =>
  state.pageData.present;

export const selectPageDataHistory = (state: StateWithHistoryWrapper) =>
  state.pageData;

export const selectPageDataOwner = (state: {
  pageDataOwner: PageDataOwner | null;
}) => state.pageDataOwner;
