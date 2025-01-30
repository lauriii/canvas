import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';
import type { CodeComponent } from '@/types/CodeComponent';
import { createSelector } from '@reduxjs/toolkit';

interface CodeComponentDialogState {
  isAddDialogOpen: boolean;
  isRenameDialogOpen: boolean;
  isDeleteDialogOpen: boolean;
  selectedCodeComponent: CodeComponent | null;
}

const initialState: CodeComponentDialogState = {
  isAddDialogOpen: false,
  isRenameDialogOpen: false,
  isDeleteDialogOpen: false,
  selectedCodeComponent: null,
};

export const codeComponentDialogSlice = createAppSlice({
  name: 'codeComponentDialog',
  initialState,
  reducers: (create) => ({
    openAddDialog: create.reducer((state) => {
      state.isAddDialogOpen = true;
      state.isRenameDialogOpen = false;
      state.isDeleteDialogOpen = false;
      state.selectedCodeComponent = null;
    }),
    openRenameDialog: create.reducer(
      (state, action: PayloadAction<CodeComponent>) => {
        state.isRenameDialogOpen = true;
        state.isAddDialogOpen = false;
        state.isDeleteDialogOpen = false;
        state.selectedCodeComponent = action.payload;
      },
    ),
    openDeleteDialog: create.reducer(
      (state, action: PayloadAction<CodeComponent>) => {
        state.isDeleteDialogOpen = true;
        state.isAddDialogOpen = false;
        state.isRenameDialogOpen = false;
        state.selectedCodeComponent = action.payload;
      },
    ),
    closeAllDialogs: create.reducer((state) => {
      state.isAddDialogOpen = false;
      state.isRenameDialogOpen = false;
      state.isDeleteDialogOpen = false;
      state.selectedCodeComponent = null;
    }),
  }),
  selectors: {
    selectDialogStates: createSelector(
      (state) => state.isAddDialogOpen,
      (state) => state.isRenameDialogOpen,
      (state) => state.isDeleteDialogOpen,
      (state) => state.selectedCodeComponent,
      (
        isAddDialogOpen,
        isRenameDialogOpen,
        isDeleteDialogOpen,
        selectedCodeComponent,
      ): CodeComponentDialogState => ({
        isAddDialogOpen,
        isRenameDialogOpen,
        isDeleteDialogOpen,
        selectedCodeComponent,
      }),
    ),
    selectSelectedCodeComponent: (state): CodeComponent | null => {
      return state.selectedCodeComponent;
    },
  },
});

export const {
  openAddDialog,
  openRenameDialog,
  openDeleteDialog,
  closeAllDialogs,
} = codeComponentDialogSlice.actions;

export const { selectDialogStates, selectSelectedCodeComponent } =
  codeComponentDialogSlice.selectors;

export default codeComponentDialogSlice.reducer;
