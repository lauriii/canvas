import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

export interface DialogSliceState {
  saveAsSection: boolean;
}

const initialState: DialogSliceState = {
  saveAsSection: false,
};

type UpdateDialogPayload = 'saveAsSection'; // only one dialog so far

export const dialogSlice = createAppSlice({
  name: 'dialog',
  initialState,
  reducers: (create) => ({
    setDialogOpen: create.reducer(
      (state, action: PayloadAction<UpdateDialogPayload>) => {
        state[action.payload] = true;
      },
    ),
    setDialogClosed: create.reducer(
      (state, action: PayloadAction<UpdateDialogPayload>) => {
        state[action.payload] = false;
      },
    ),
  }),
  selectors: {
    selectDialogOpen: (dialog): DialogSliceState => {
      return dialog;
    },
  },
});

export const { setDialogOpen, setDialogClosed } = dialogSlice.actions;
export const { selectDialogOpen } = dialogSlice.selectors;
