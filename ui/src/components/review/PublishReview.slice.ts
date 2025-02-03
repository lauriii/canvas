import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import type {
  ConflictError,
  ErrorResponse,
  PendingChanges,
} from '@/services/pendingChangesApi';

interface postPreviewSignalSliceState {
  postPreviewCompleted: boolean;
  previousPendingChanges?: PendingChanges;
  conflicts?: ConflictError[];
  errors?: ErrorResponse;
}

const initialState: postPreviewSignalSliceState = {
  postPreviewCompleted: false,
};

export const publishReviewSlice = createSlice({
  name: 'publishReview',
  initialState,
  reducers: {
    setPostPreviewCompleted(state, action: PayloadAction<boolean>) {
      state.postPreviewCompleted = action.payload;
    },
    setPreviousPendingChanges(
      state,
      action: PayloadAction<PendingChanges | undefined>,
    ) {
      state.previousPendingChanges = action.payload;
    },
    setConflicts(state, action: PayloadAction<ConflictError[] | undefined>) {
      state.conflicts = action.payload;
    },
    setErrors(state, action: PayloadAction<ErrorResponse | undefined>) {
      state.errors = action.payload;
    },
  },
  selectors: {
    selectPostPreviewCompletedStatus: (postPreviewSignal): boolean => {
      return postPreviewSignal.postPreviewCompleted;
    },
    selectPreviousPendingChanges: (state): PendingChanges | undefined => {
      return state?.previousPendingChanges;
    },
    selectConflicts: (state): ConflictError[] | undefined => {
      return state?.conflicts;
    },
    selectErrors: (state): ErrorResponse | undefined => {
      return state?.errors;
    },
  },
});

export const {
  setPostPreviewCompleted,
  setPreviousPendingChanges,
  setConflicts,
  setErrors,
} = publishReviewSlice.actions;

export const {
  selectPostPreviewCompletedStatus,
  selectPreviousPendingChanges,
  selectConflicts,
  selectErrors,
} = publishReviewSlice.selectors;
