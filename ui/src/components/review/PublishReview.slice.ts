import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';

interface postPreviewSignalSliceState {
  postPreviewCompleted: boolean;
}

const initialState: postPreviewSignalSliceState = {
  postPreviewCompleted: false,
};

export const postPreviewSignalSlice = createSlice({
  name: 'postPreviewSignal',
  initialState,
  reducers: {
    setPostPreviewCompleted(state, action: PayloadAction<boolean>) {
      state.postPreviewCompleted = action.payload;
    },
  },
  selectors: {
    selectPostPreviewCompletedStatus: (postPreviewSignal): boolean => {
      return postPreviewSignal.postPreviewCompleted;
    },
  },
});

export const { setPostPreviewCompleted } = postPreviewSignalSlice.actions;

export const { selectPostPreviewCompletedStatus } =
  postPreviewSignalSlice.selectors;
