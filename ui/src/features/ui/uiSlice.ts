import { createAppSlice } from "../../app/createAppSlice";
import type { PayloadAction } from "@reduxjs/toolkit";


export interface DraggingStatus {
  isDragging: boolean;
  treeDragging: boolean;
  listDragging: boolean;
  previewDragging: boolean;
}

export interface uiSliceState {
  pending: boolean;
  dragging: DraggingStatus;
}

const initialState: uiSliceState = {
  pending: false,
  dragging: {
    isDragging: false,
    treeDragging: false,
    listDragging: false,
    previewDragging: false,
  },
};

// If you are not using async thunks you can use the standalone `createSlice`.
export const uiSlice = createAppSlice({
  name: "ui",
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: create => ({
    setPending: create.reducer((state, action: PayloadAction<boolean>) => {
      state.pending = action.payload;
    }),
    setTreeDragging: create.reducer((state, action: PayloadAction<boolean>) => {
      state.dragging.isDragging = action.payload;
      state.dragging.treeDragging = action.payload;
    }),
    setPreviewDragging: create.reducer((state, action: PayloadAction<boolean>) => {
      state.dragging.isDragging = action.payload;
      state.dragging.previewDragging = action.payload;
    }),
    setListDragging: create.reducer((state, action: PayloadAction<boolean>) => {
      state.dragging.isDragging = action.payload;
      state.dragging.listDragging = action.payload;
    }),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectDragging: (ui): DraggingStatus => {
      return ui.dragging;
    },
  },
});

// Action creators are generated for each case reducer function.
export const { setPending, setTreeDragging, setPreviewDragging, setListDragging } = uiSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectDragging } = uiSlice.selectors;

