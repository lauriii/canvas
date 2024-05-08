import { createAppSlice } from "../../app/createAppSlice";
import type { AppThunk } from "../../app/store";
import type { PayloadAction } from "@reduxjs/toolkit";

export interface InsertPosition {
  selector: string;
  path: string;
  placement: string;
  dragged: string;
  node: string;
}

export interface DraggingStatus {
  isDragging: boolean;
  treeDragging: boolean;
  previewDragging: boolean;
}

export interface LayoutUISliceState {
  pending: boolean;
  insertPosition: InsertPosition;
  dragging: DraggingStatus;
}

const initialState: LayoutUISliceState = {
  insertPosition: {
    selector: "",
    path: "",
    placement: "",
    dragged: "",
    node: "",
  },
  pending: false,
  dragging: {
    isDragging: false,
    treeDragging: false,
    previewDragging: false,
  },
};

// If you are not using async thunks you can use the standalone `createSlice`.
export const layoutUISlice = createAppSlice({
  name: "layoutUI",
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
    setInsertPosition: create.reducer((state, action: PayloadAction<InsertPosition>) => {
      state.insertPosition = action.payload;
    }),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectInsertPosition: (layoutUI): InsertPosition => {
      return layoutUI.insertPosition;
    },
    selectDragging: (layoutUI): DraggingStatus => {
      return layoutUI.dragging;
    },
  },
});

// Action creators are generated for each case reducer function.
export const { setPending, setTreeDragging, setPreviewDragging, setInsertPosition } = layoutUISlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectInsertPosition, selectDragging } = layoutUISlice.selectors;

// We can also write thunks by hand, which may contain both sync and async logic.
// Here's an example of conditionally dispatching actions based on current state.
// export const incrementIfOdd =
//   (amount: number): AppThunk =>
//     (dispatch, getState) => {
//       const currentValue = selectCount(getState())
//
//       if (currentValue % 2 === 1 || currentValue % 2 === -1) {
//         dispatch(incrementByAmount(amount))
//       }
//     }
