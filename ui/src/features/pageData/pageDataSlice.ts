import { createSlice } from '@reduxjs/toolkit';
import type { PayloadAction } from '@reduxjs/toolkit';
import type { StateWithHistory } from 'redux-undo';

interface Values {
  [key: string]: any;
}

export interface PageDataState extends Values {}

const initialState: PageDataState = {};

export interface StateWithHistoryWrapper {
  pageData: StateWithHistory<PageDataState>;
}

export const pageDataSlice = createSlice({
  name: 'pageData',
  initialState,
  reducers: (create) => ({
    setPageData: create.reducer((state, action: PayloadAction<Values>) => {
      return {
        ...state,
        ...action.payload,
      };
    }),
  }),
});

export const { setPageData } = pageDataSlice.actions;

export const pageDataReducer = pageDataSlice.reducer;

export const selectPageData = (state: StateWithHistoryWrapper) =>
  state.pageData.present;

export const selectPageDataHistory = (state: StateWithHistoryWrapper) =>
  state.pageData;
