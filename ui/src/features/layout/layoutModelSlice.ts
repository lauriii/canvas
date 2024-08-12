// cspell:ignore uuidv
import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import _ from 'lodash';
import {
  findNodeByUuid,
  findNodePathByUuid,
  moveNodeToPath,
  insertNodeAtPath,
  removeNodeByUuid,
} from './layoutUtils';
import { v4 as uuidv4 } from 'uuid';
import type { UUID } from '@/types/UUID';
import type { AppDispatch } from '@/app/store';
import type { StateWithHistory } from 'redux-undo';
import type { FieldData } from '@/types/Component';

export interface LayoutNode {
  name?: string;
  uuid: UUID;
  nodeType: 'slot' | 'component' | 'root';
  type?: string;
  children: LayoutNode[];
  props?: {} | undefined;
}

export interface LayoutModelSliceState {
  layout: LayoutNode;
  model: {
    [key: string]: ComponentModel;
  };
  initialized: boolean;
}

export const initialState: LayoutModelSliceState = {
  layout: {
    uuid: 'root',
    nodeType: 'root',
    name: 'root',
    children: [],
  },
  model: {},
  initialized: false,
};

// This wrapper is necessary because when using slices with redux-undo,
// you reference state.[sliceName].present.
export interface StateWithHistoryWrapper {
  layoutModel: StateWithHistory<LayoutModelSliceState>;
}

type MoveNodePayload = {
  uuid: string | undefined;
  to: number[] | undefined;
};

type ShiftNodePayload = {
  uuid: string | undefined;
  direction: 'up' | 'down';
};

type DuplicateNodePayload = {
  uuid: string;
};

type InsertNodePayload = {
  newNode: LayoutNode | undefined;
  to: number[] | undefined;
  model: InitialPropData | undefined;
};

type AddNewNodePayload = {
  newNode: string | undefined;
  to: number[] | undefined;
  componentFieldData: FieldData | undefined;
};

type SortNodePayload = {
  uuid: string | undefined;
  to: number | undefined;
};

type UpdateNodePayload = {
  uuid: string | undefined;
  model: {};
};

type InitialPropData = {
  [key: string]: any;
};

export interface ComponentModel {
  [key: string]: string | boolean | [] | number;
  name: string;
}

// If you are not using async thunks you can use the standalone `createSlice`.
export const layoutModelSlice = createSlice({
  name: 'layoutModel',
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: (create) => ({
    // Reducers for state.layout
    deleteNode: create.reducer((state, action: PayloadAction<string>) => {
      state.layout = removeNodeByUuid(state.layout, action.payload);
      delete state.model[action.payload];
    }),
    duplicateNode: create.reducer(
      (state, action: PayloadAction<DuplicateNodePayload>) => {
        const { uuid } = action.payload;
        const cloneNode = _.cloneDeep(findNodeByUuid(state.layout, uuid));
        if (!cloneNode) {
          console.error(`Cannot duplicate ${uuid}. Check the uuid is valid.`);
          return;
        }
        const newUuid = uuidv4();
        cloneNode.uuid = newUuid;
        state.model[newUuid] = _.cloneDeep(state.model[uuid]);

        const nodePath = findNodePathByUuid(state.layout, uuid);
        if (nodePath === null) {
          console.error(
            `Cannot find ${uuid} in layout. Check the uuid is valid.`,
          );
          return;
        }
        nodePath[nodePath.length - 1]++;
        state.layout = insertNodeAtPath(state.layout, nodePath, cloneNode);
      },
    ),
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
        const { newNode, to, model } = action.payload;
        if (!newNode || !Array.isArray(to)) {
          console.error(
            `Cannot move ${newNode} to position ${to}. Check both uuid and to are defined/valid.`,
          );
          return;
        }

        state.layout = insertNodeAtPath(state.layout, to, newNode);
        state.model[newNode.uuid] = { ...state.model[newNode.uuid], ...model };
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
    shiftNode: create.reducer(
      (state, action: PayloadAction<ShiftNodePayload>) => {
        const { uuid, direction } = action.payload;
        if (!uuid) {
          console.error(
            `Cannot shift ${uuid} ${direction}. Check both uuid and direction are defined/valid.`,
          );
          return;
        }

        const cloneNode = _.cloneDeep(findNodeByUuid(state.layout, uuid));
        const nodePath = findNodePathByUuid(state.layout, uuid);
        if (cloneNode && nodePath) {
          const newPos =
            direction === 'down'
              ? nodePath[nodePath.length - 1] + 1
              : Math.max(0, nodePath[nodePath.length - 1] - 1);
          const insertPosition = [...nodePath.slice(0, -1), newPos];
          const newLayout = removeNodeByUuid(state.layout, uuid);

          state.layout = insertNodeAtPath(newLayout, insertPosition, cloneNode);
        }
      },
    ),
    setLayoutModel: create.reducer(
      (state, action: PayloadAction<LayoutModelSliceState>) => {
        const { layout, model, initialized } = action.payload;
        state.layout = layout;
        state.model = model;
        state.initialized = initialized;
      },
    ),
    // Reducers for state.model
    updateNodeModel: create.reducer(
      (state, action: PayloadAction<UpdateNodePayload>) => {
        const { uuid, model } = action.payload;
        const randomData = { randomProp: 'random' };
        if (uuid) {
          state.model[uuid] = { ...state.model[uuid], ...model, ...randomData };
        }
      },
    ),
  }),
});

export const addNewComponentToLayout =
  (payload: AddNewNodePayload) => (dispatch: AppDispatch) => {
    if (payload.newNode && payload.to) {
      const uuid = uuidv4();
      const initialData: InitialPropData = {};
      if (payload?.componentFieldData) {
        // @todo Update this logic in https://www.drupal.org/project/experience_builder/issues/3455942
        Object.keys(payload.componentFieldData).forEach((propName) => {
          if (payload.componentFieldData?.[propName]?.['default_values']) {
            initialData[propName] =
              payload.componentFieldData[propName]['default_values'];
          }
        });
      }
      dispatch(
        insertNode({
          to: payload.to,
          newNode: {
            uuid: uuid,
            children: [],
            nodeType: 'component',
            type: payload.newNode,
          },
          model: initialData,
        }),
      );
    }
  };

// Action creators are generated for each case reducer function.
export const {
  deleteNode,
  setLayoutModel,
  duplicateNode,
  moveNode,
  shiftNode,
  sortNode,
  insertNode,
  updateNodeModel,
} = layoutModelSlice.actions;

export const layoutModelReducer = layoutModelSlice.reducer;

// When using redux-undo, you reference the current state by state.[sliceName].present.[targetKey].
// These selectors are written outside the slice because the type of state is different. Here, we need
// to be able to access the history, so we use the StateWithHistoryWrapper type.
export const selectLayout = (state: StateWithHistoryWrapper) =>
  state.layoutModel.present.layout;
export const selectModel = (state: StateWithHistoryWrapper) =>
  state.layoutModel.present.model;
export const selectHistory = (state: StateWithHistoryWrapper) =>
  state.layoutModel;
export const selectInitialized = (state: StateWithHistoryWrapper) =>
  state.layoutModel.present.initialized;
