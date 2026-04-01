import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';

interface NotificationsState {
  pageOpenedAt: number;
  activityCenterOpen: boolean;
}

const initialState: NotificationsState = {
  pageOpenedAt: Date.now(),
  activityCenterOpen: false,
};

export const notificationsSlice = createSlice({
  name: 'notifications',
  initialState,
  reducers: {
    setActivityCenterOpen(state, action: PayloadAction<boolean>) {
      state.activityCenterOpen = action.payload;
    },
  },
  selectors: {
    selectActivityCenterOpen: (state) => state.activityCenterOpen,
    selectPageOpenedAt: (state) => state.pageOpenedAt,
  },
});

export const { setActivityCenterOpen } = notificationsSlice.actions;
export const { selectActivityCenterOpen, selectPageOpenedAt } =
  notificationsSlice.selectors;
