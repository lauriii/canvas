import { createAppSlice } from '@/app/createAppSlice';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { Pattern } from '@/types/Pattern';

/** Payload for the template-editor "Enable editing" / "Edit label" dialog. */
export interface ExposeSlotDialogData {
  mode: 'expose' | 'editLabel';
  /** UUID of the component that hosts the slot. */
  componentUuid: string;
  /** Machine name of the slot within the host component. */
  slotName: string;
  /** Human-readable slot title, used for the dialog default label. */
  slotTitle: string;
  /** Present in editLabel mode: the field name of the slot being relabeled. */
  fieldName?: string;
  /** Present in editLabel mode: the current label. */
  label?: string;
}

/** Payload for the "Remove exposed slot" confirmation dialog. */
export interface RemoveExposedSlotDialogData {
  fieldName: string;
  label: string;
}

/** Payload for the delete-protection dialog (component hosts exposed slots). */
export interface DeleteComponentWithExposedSlotsDialogData {
  componentUuid: string;
  componentName: string;
  fieldNames: string[];
  labels: string[];
}

/** Payload of each dialog that opens with data, keyed by dialog. */
interface DialogDataMap {
  deletePatternConfirm: Pattern;
  renamePatternConfirm: Pattern;
  exposeSlot: ExposeSlotDialogData;
  removeExposedSlotConfirm: RemoveExposedSlotDialogData;
  deleteComponentWithExposedSlots: DeleteComponentWithExposedSlotsDialogData;
}

type DataDialog = keyof DialogDataMap;

/**
 * A dialog's state: its full payload while open, emptied once closed. Checking
 * `open` narrows `data` to the payload.
 */
type DialogWithData<T> =
  { open: true; data: T } | { open: false; data: Partial<T> };

type DataDialogsState = {
  [K in DataDialog]: DialogWithData<DialogDataMap[K]>;
};

export interface DialogSliceState extends DataDialogsState {
  saveAsPattern: boolean;
  extension: boolean;
}

const closedDialog = { open: false, data: {} } as const;

const initialState: DialogSliceState = {
  saveAsPattern: false,
  extension: false,
  deletePatternConfirm: closedDialog,
  exposeSlot: closedDialog,
  removeExposedSlotConfirm: closedDialog,
  deleteComponentWithExposedSlots: closedDialog,
  renamePatternConfirm: closedDialog,
};

type UpdateDialogPayload = keyof Omit<DialogSliceState, DataDialog>;

type UpdateDialogWithDataPayload = {
  [K in DataDialog]: { operation: K; data: DialogDataMap[K] };
}[DataDialog];

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
        const { operation, data } = action.payload;
        // TypeScript cannot correlate `operation` with `data` across the
        // union; the payload type above already pairs them.
        (state as Record<DataDialog, DialogWithData<unknown>>)[operation] = {
          open: true,
          data,
        };
      },
    ),
    setDialogWithDataClosed: create.reducer(
      (state, action: PayloadAction<DataDialog>) => {
        state[action.payload] = closedDialog;
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
