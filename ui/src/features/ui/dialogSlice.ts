import { createAppSlice } from '@/app/createAppSlice';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { Pattern } from '@/types/Pattern';

/** Payload for the template-editor "Expose slot" / "Edit label" dialog. */
export interface ExposeSlotDialogData {
  mode: 'expose' | 'editLabel';
  /** UUID of the component that hosts the slot. */
  componentUuid: string;
  /** Machine name of the slot within the host component. */
  slotName: string;
  /** Human-readable slot title, used for the dialog default label. */
  slotTitle: string;
  /** Present in editLabel mode: the immutable alias being relabeled. */
  alias?: string;
  /** Present in editLabel mode: the current label. */
  label?: string;
}

/** Payload for the "Remove exposed slot" confirmation dialog. */
export interface RemoveExposedSlotDialogData {
  alias: string;
  label: string;
}

/** Payload for the delete-protection dialog (component hosts exposed slots). */
export interface DeleteComponentWithExposedSlotsDialogData {
  componentUuid: string;
  componentName: string;
  aliases: string[];
  labels: string[];
}

export interface DialogSliceState {
  saveAsPattern: boolean;
  extension: boolean;
  deletePatternConfirm: {
    open: boolean;
    data: Pattern | {};
  };
  renamePatternConfirm: {
    open: boolean;
    data: Pattern | {};
  };
  exposeSlot: {
    open: boolean;
    data: ExposeSlotDialogData | {};
  };
  removeExposedSlotConfirm: {
    open: boolean;
    data: RemoveExposedSlotDialogData | {};
  };
  deleteComponentWithExposedSlots: {
    open: boolean;
    data: DeleteComponentWithExposedSlotsDialogData | {};
  };
}

const initialState: DialogSliceState = {
  saveAsPattern: false,
  extension: false,
  deletePatternConfirm: {
    open: false,
    data: {},
  },
  renamePatternConfirm: {
    open: false,
    data: {},
  },
  exposeSlot: {
    open: false,
    data: {},
  },
  removeExposedSlotConfirm: {
    open: false,
    data: {},
  },
  deleteComponentWithExposedSlots: {
    open: false,
    data: {},
  },
};

type UpdateDialogPayload = keyof Omit<
  DialogSliceState,
  | 'deletePatternConfirm'
  | 'renamePatternConfirm'
  | 'exposeSlot'
  | 'removeExposedSlotConfirm'
  | 'deleteComponentWithExposedSlots'
>;

type UpdateDialogWithDataPayload = {
  operation: keyof Omit<DialogSliceState, UpdateDialogPayload>;
  data: any;
};

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
    setDialogWithDataOpen: create.reducer(
      (state, action: PayloadAction<UpdateDialogWithDataPayload>) => {
        state[action.payload.operation] = {
          open: true,
          data: action.payload.data,
        };
      },
    ),
    setDialogWithDataClosed: create.reducer(
      (
        state,
        action: PayloadAction<
          keyof Omit<DialogSliceState, UpdateDialogPayload>
        >,
      ) => {
        state[action.payload] = {
          open: false,
          data: {},
        };
      },
    ),
  }),
  selectors: {
    selectDialogOpen: (dialog): DialogSliceState => {
      return dialog;
    },
  },
});

export const {
  setDialogOpen,
  setDialogClosed,
  setDialogWithDataOpen,
  setDialogWithDataClosed,
} = dialogSlice.actions;
export const { selectDialogOpen } = dialogSlice.selectors;
