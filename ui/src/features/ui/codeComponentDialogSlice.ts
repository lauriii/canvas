import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import { createSelector } from '@reduxjs/toolkit';

interface CodeComponentDialogState {
  isAddDialogOpen: boolean;
  isRenameDialogOpen: boolean;
  isDeleteDialogOpen: boolean;
  selectedCodeComponent: CodeComponentSerialized | null;
  isRemoveFromComponentsDialogOpen: boolean;
  isInLayoutDialogOpen: boolean;
}

const initialState: CodeComponentDialogState = {
  isAddDialogOpen: false,
  isRenameDialogOpen: false,
  isDeleteDialogOpen: false,
  selectedCodeComponent: null,
  isRemoveFromComponentsDialogOpen: false,
  isInLayoutDialogOpen: false,
};

export const codeComponentDialogSlice = createAppSlice({
  name: 'codeComponentDialog',
  initialState,
  reducers: (create) => ({
    openAddDialog: create.reducer((state) => {
      state.isAddDialogOpen = true;
      state.isRenameDialogOpen = false;
      state.isDeleteDialogOpen = false;
      state.isRemoveFromComponentsDialogOpen = false;
      state.selectedCodeComponent = null;
      state.isInLayoutDialogOpen = false;
    }),
    openRenameDialog: create.reducer(
      (state, action: PayloadAction<CodeComponentSerialized>) => {
        state.isRenameDialogOpen = true;
        state.isAddDialogOpen = false;
        state.isDeleteDialogOpen = false;
        state.isRemoveFromComponentsDialogOpen = false;
        state.selectedCodeComponent = action.payload;
        state.isInLayoutDialogOpen = false;
      },
    ),
    openDeleteDialog: create.reducer(
      (state, action: PayloadAction<CodeComponentSerialized>) => {
        state.isDeleteDialogOpen = true;
        state.isAddDialogOpen = false;
        state.isRenameDialogOpen = false;
        state.isRemoveFromComponentsDialogOpen = false;
        state.selectedCodeComponent = action.payload;
        state.isInLayoutDialogOpen = false;
      },
    ),
    closeAllDialogs: create.reducer((state) => {
      state.isAddDialogOpen = false;
      state.isRenameDialogOpen = false;
      state.isDeleteDialogOpen = false;
      state.isRemoveFromComponentsDialogOpen = false;
      state.selectedCodeComponent = null;
      state.isInLayoutDialogOpen = false;
    }),
    // Only for exposed components.
    openRemoveFromComponentsDialog: create.reducer(
      (state, action: PayloadAction<CodeComponentSerialized>) => {
        state.isAddDialogOpen = false;
        state.isRenameDialogOpen = false;
        state.isDeleteDialogOpen = false;
        state.isRemoveFromComponentsDialogOpen = true;
        state.selectedCodeComponent = action.payload;
        state.isInLayoutDialogOpen = false;
      },
    ),
    // Only for exposed components.
    openInLayoutDialog: create.reducer((state) => {
      state.isAddDialogOpen = false;
      state.isRenameDialogOpen = false;
      state.isDeleteDialogOpen = false;
      state.isRemoveFromComponentsDialogOpen = false;
      state.isInLayoutDialogOpen = true;
    }),
  }),
  selectors: {
    selectDialogStates: createSelector(
      (state) => state.isAddDialogOpen,
      (state) => state.isRenameDialogOpen,
      (state) => state.isDeleteDialogOpen,
      (state) => state.selectedCodeComponent,
      (state) => state.isRemoveFromComponentsDialogOpen,
      (state) => state.isInLayoutDialogOpen,
      (
        isAddDialogOpen,
        isRenameDialogOpen,
        isDeleteDialogOpen,
        selectedCodeComponent,
        isRemoveFromComponentsDialogOpen,
        isInLayoutDialogOpen,
      ): CodeComponentDialogState => ({
        isAddDialogOpen,
        isRenameDialogOpen,
        isDeleteDialogOpen,
        selectedCodeComponent,
        isRemoveFromComponentsDialogOpen,
        isInLayoutDialogOpen,
      }),
    ),
    selectSelectedCodeComponent: (state): CodeComponentSerialized | null => {
      return state.selectedCodeComponent;
    },
  },
});

export const {
  openAddDialog,
  openRenameDialog,
  openDeleteDialog,
  closeAllDialogs,
  openRemoveFromComponentsDialog,
  openInLayoutDialog,
} = codeComponentDialogSlice.actions;

export const { selectDialogStates, selectSelectedCodeComponent } =
  codeComponentDialogSlice.selectors;

export default codeComponentDialogSlice.reducer;
