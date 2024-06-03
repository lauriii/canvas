// cspell:ignore uuidv
import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import _ from 'lodash';
import { createNewModel } from '../model/modelSlice';
import {
  findNodeByUuid,
  findNodePathByUuid,
  moveNodeToPath,
  insertNodeAtPath,
  removeNodeByUuid,
} from './layoutUtils';
import { v4 as uuidv4 } from 'uuid';
import type { UUID } from '../../types/UUID';
import type { AppDispatch } from '../../app/store';

export interface LayoutNode {
  name?: string;
  uuid: UUID;
  type: 'slot' | 'component' | 'root';
  componentType?: string;
  children: LayoutNode[];
}

export interface LayoutSliceState {
  layout: LayoutNode;
}

const initialState: LayoutSliceState = {
  layout: {
    uuid: 'root',
    type: 'root',
    name: 'root',
    children: [],
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
  name: 'layout',
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: (create) => ({
    deleteNode: create.reducer((state, action: PayloadAction<string>) => {
      state.layout = removeNodeByUuid(state.layout, action.payload);
    }),
    moveNode: create.reducer(
      (state, action: PayloadAction<MoveNodePayload>) => {
        const { uuid, to } = action.payload;
        if (!uuid || !Array.isArray(to)) {
          console.error(
            `Cannot move ${uuid} to position ${to}. Check both uuid and to are defined/valid.`,
          );
          return;
        }

        state.layout = moveNodeToPath(state.layout, uuid, to);
      },
    ),
    insertNode: create.reducer(
      (state, action: PayloadAction<InsertNodePayload>) => {
        const { newNode, to } = action.payload;
        if (!newNode || !Array.isArray(to)) {
          console.error(
            `Cannot move ${newNode} to position ${to}. Check both uuid and to are defined/valid.`,
          );
          return;
        }

        state.layout = insertNodeAtPath(state.layout, to, newNode);
      },
    ),
    sortNode: create.reducer(
      (state, action: PayloadAction<SortNodePayload>) => {
        const { uuid, to } = action.payload;
        if (!uuid || to === undefined) {
          console.error(
            `Cannot sort ${uuid} to position ${to}. Check both uuid and to are defined/valid.`,
          );
          return;
        }

        const cloneNode = _.cloneDeep(findNodeByUuid(state.layout, uuid));
        const nodePath = findNodePathByUuid(state.layout, uuid);
        if (cloneNode && nodePath) {
          const insertPosition = [...nodePath.slice(0, -1), to];
          const newLayout = removeNodeByUuid(state.layout, uuid);

          state.layout = insertNodeAtPath(newLayout, insertPosition, cloneNode);
        }
      },
    ),
    setNewLayout: create.reducer(
      (state, action: PayloadAction<LayoutSliceState>) => {
        state.layout = action.payload.layout;
      },
    ),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectLayout: (layout) => layout.layout,
  },
});

export const addNewComponentToLayout =
  (payload: InsertNodePayload) => (dispatch: AppDispatch) => {
    if (payload.newNode && payload.to) {
      payload.newNode.uuid = uuidv4();
      const name = payload.newNode.name || 'Unknown component';
      dispatch(insertNode(payload));
      fetch(`/xb-render-component/${payload.newNode.componentType}`)
        .then(res => res.json())
        .then(result => {
          dispatch(
            createNewModel({
              uuid: payload?.newNode?.uuid,
              initialData: { exampleData: 'testing', name: name, markup: result.markup, },
            }),
          );
        })
    }
  };

// Action creators are generated for each case reducer function.
export const { deleteNode, setNewLayout, moveNode, sortNode, insertNode } =
  layoutSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectLayout } = layoutSlice.selectors;
