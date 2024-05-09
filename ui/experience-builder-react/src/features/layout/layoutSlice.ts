import type { PayloadAction } from "@reduxjs/toolkit";
import { createSlice } from "@reduxjs/toolkit";
import _ from "lodash";
import { findNodeByUuid, findNodePathByUuid, moveNodeToPath, insertNodeAtPath, removeNodeByUuid } from "./layoutUtils";

export interface LayoutNode {
  uuid: string;
  name: string;
  children: LayoutNode[];
}

export interface RootLayoutNode {
  uuid: string;
  name: string;
  children: LayoutNode[];
}

export interface LayoutSliceState {
  layout: RootLayoutNode;
}

const initialState: LayoutSliceState = {
  layout: {
    uuid: "root",
    name: "root",
    children: [
      {
        name: "Component 1",
        uuid: "1",
        children: [],
      },
      {
        name: "Component 2",
        uuid: "2",
        children: [],
      },
      {
        name: "Component 3",
        uuid: "3",
        children: [
          {
            name: "Component 4",
            uuid: "4",
            children: [
              {
                name: "Component 6",
                uuid: "6",
                children: [],
              },
            ],
          },
          {
            name: "Component 5",
            uuid: "5",
            children: [],
          },
        ],
      },
    ],
  },
};

type MoveNodePayload = {
  uuid: string | undefined;
  to: number[] | undefined;
};

type InsertNodePayload = {
  newNode: LayoutNode | undefined;
  to: number[] | undefined;
};

type SortNodePayload = {
  uuid: string | undefined;
  to: number | undefined;
};

// If you are not using async thunks you can use the standalone `createSlice`.
export const layoutSlice = createSlice({
  name: "layout",
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: create => ({
    deleteNode: create.reducer((state, action: PayloadAction<string>) => {
      state.layout = removeNodeByUuid(state.layout, action.payload);
    }),
    moveNode: create.reducer((state, action: PayloadAction<MoveNodePayload>) => {
      const { uuid, to } = action.payload;
      if (!uuid || !Array.isArray(to)) {
        console.error(`Cannot move ${uuid} to position ${to}. Check both uuid and to are defined/valid.`);
        return;
      }

      state.layout = moveNodeToPath(state.layout, uuid, to);
    }),
    insertNode: create.reducer((state, action: PayloadAction<InsertNodePayload>) => {
      const { newNode, to } = action.payload;
      if (!newNode || !Array.isArray(to)) {
        console.error(`Cannot move ${newNode} to position ${to}. Check both uuid and to are defined/valid.`);
        return;
      }

      state.layout = insertNodeAtPath(state.layout, to, newNode);
    }),
    sortNode: create.reducer((state, action: PayloadAction<SortNodePayload>) => {
      const { uuid, to } = action.payload;
      if (!uuid || to === undefined) {
        console.error(`Cannot sort ${uuid} to position ${to}. Check both uuid and to are defined/valid.`);
        return;
      }

      const cloneNode = _.cloneDeep(findNodeByUuid(state.layout, uuid));
      const nodePath = findNodePathByUuid(state.layout, uuid);
      if (cloneNode && nodePath) {
        const insertPosition = [...nodePath.slice(0, -1), to];
        const newLayout = removeNodeByUuid(state.layout, uuid);

        state.layout = insertNodeAtPath(newLayout, insertPosition, cloneNode);
      }
    }),
    setNewLayout: create.reducer((state, action: PayloadAction<LayoutSliceState>) => {
      state.layout = action.payload.layout;
    }),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectLayout: layout => layout.layout,
  },
});

// Action creators are generated for each case reducer function.
export const { deleteNode, setNewLayout, moveNode, sortNode, insertNode } = layoutSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectLayout } = layoutSlice.selectors;

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
