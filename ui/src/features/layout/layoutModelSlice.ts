// cspell:ignore uuidv
import type { AppDispatch } from '@/app/store';
import { setSelectedComponent } from '@/features/ui/uiSlice';
import type { Component } from '@/types/Component';
import type { UUID } from '@/types/UUID';
import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import _ from 'lodash';
import type { StateWithHistory } from 'redux-undo';
import { v4 as uuidv4 } from 'uuid';
import {
  findNodeByUuid,
  findNodePathByUuid,
  insertNodeAtPath,
  moveNodeToPath,
  recurseNodes,
  removeNodeByUuid,
  replaceUUIDsAndUpdateModel,
} from './layoutUtils';

export interface RootNode {
  name?: string;
  nodeType: 'root';
  uuid: 'root';
  children: Node[];
}

export interface Node {
  name?: string;
  uuid: UUID;
  nodeType: 'slot' | 'component';
  type?: string;
  children: Node[];
  props?: {} | undefined;
}

export type LayoutNode = RootNode | Node;

export interface RootLayoutModel {
  layout: RootNode;
  model: ComponentModels;
}

export interface LayoutModelSliceState extends RootLayoutModel {
  initialized: boolean;
}

export type ComponentModels = Record<string, ComponentModel>;

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

type InsertMultipleNodesPayload = {
  to: number[] | undefined;
  layoutModel: RootLayoutModel;
  /**
   * Pass an optional UUID that will be assigned to the last, top level node being inserted. Allows you to define the UUID
   * so that you can then do something with the newly inserted node using that UUID.
   */
  useUUID?: string;
};

type AddNewNodePayload = {
  to: number[] | undefined;
  component: Component | undefined;
};

type AddNewSectionPayload = {
  to: number[] | undefined;
  layoutModel: RootLayoutModel;
};

type SortNodePayload = {
  uuid: string | undefined;
  to: number | undefined;
};

type UpdateNodePayload = {
  uuid: string | undefined;
  model: {};
};

export interface ComponentModel {
  [key: string]: string | boolean | [] | number | {};
}

export const layoutModelSlice = createSlice({
  name: 'layoutModel',
  initialState,
  reducers: (create) => ({
    deleteNode: create.reducer((state, action: PayloadAction<string>) => {
      const deletedComponent = findNodeByUuid(state.layout, action.payload);
      const removableModelsUuids = [action.payload];
      if (deletedComponent) {
        recurseNodes(deletedComponent, (node: LayoutNode) => {
          removableModelsUuids.push(node.uuid);
        });
      }
      for (const uuid of removableModelsUuids) {
        if (state.model[uuid]) delete state.model[uuid];
      }
      state.layout = removeNodeByUuid(state.layout, action.payload) as RootNode;
    }),
    duplicateNode: create.reducer(
      (state, action: PayloadAction<DuplicateNodePayload>) => {
        const { uuid } = action.payload;
        const nodeToDuplicate = findNodeByUuid(state.layout, uuid);

        if (!nodeToDuplicate) {
          console.error(`Cannot duplicate ${uuid}. Check the uuid is valid.`);
          return;
        }

        const { updatedNode, updatedModel } = replaceUUIDsAndUpdateModel(
          nodeToDuplicate,
          state.model,
        );

        // Add the updated model to the state
        state.model = { ...state.model, ...updatedModel };

        const nodePath = findNodePathByUuid(state.layout, uuid);
        if (nodePath === null) {
          console.error(
            `Cannot find ${uuid} in layout. Check the uuid is valid.`,
          );
          return;
        }
        nodePath[nodePath.length - 1]++;
        state.layout = insertNodeAtPath(
          state.layout,
          nodePath,
          updatedNode,
        ) as RootNode;
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
    insertNodes: create.reducer(
      (state, action: PayloadAction<InsertMultipleNodesPayload>) => {
        const { layoutModel, to, useUUID } = action.payload;

        if (!Array.isArray(to)) {
          console.error(
            `Cannot insert nodes. Invalid parameters: newNodes: ${layoutModel}, to: ${to}.`,
          );
          return;
        }

        let updatedModel: ComponentModels = { ...state.model };
        let newLayout: RootNode = _.cloneDeep(state.layout);
        const rootNode = layoutModel.layout;
        const model = layoutModel.model;

        // Loop through each node in reverse order to maintain the correct insert positions
        for (let i = rootNode.children.length - 1; i >= 0; i--) {
          const node = rootNode.children[i];
          const specifyUUID = i === 0;
          const { updatedNode, updatedModel: nodeUpdatedModel } =
            replaceUUIDsAndUpdateModel(
              node,
              model,
              specifyUUID ? useUUID : undefined,
            );
          updatedModel = { ...updatedModel, ...nodeUpdatedModel };
          newLayout = insertNodeAtPath(newLayout, to, updatedNode);
        }

        state.model = updatedModel;
        state.layout = newLayout;
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
    updateNodeModel: create.reducer(
      (state, action: PayloadAction<UpdateNodePayload>) => {
        const { uuid, model } = action.payload;
        const randomData = { randomProp: 'random' };
        if (uuid) {
          state.model[uuid] = { ...state.model[uuid], ...model, ...randomData };
        }
      },
    ),
    // Nearly identical to updateNodeModel above, but makes it possible to
    // remove props by not including the prior state.model[uuid] in the value
    // update.
    updateNodeModelForce: create.reducer(
      (state, action: PayloadAction<UpdateNodePayload>) => {
        const { uuid, model } = action.payload;
        if (uuid) {
          state.model[uuid] = { ...(model as ComponentModel) };
        }
      },
    ),
  }),
});

export const addNewComponentToLayout =
  (payload: AddNewNodePayload) => (dispatch: AppDispatch) => {
    if (!payload.to || !payload.component) {
      return;
    }

    const initialData: ComponentModel = {};
    const children: Node[] = [];
    const uuid = uuidv4();

    // Populate the model data with the default values
    if (payload.component?.field_data) {
      Object.keys(payload.component.field_data).forEach((propName) => {
        if (payload.component?.field_data?.[propName]?.['default_values']) {
          initialData[propName] =
            payload.component?.field_data[propName]['default_values'];
        }
      });
    }

    // Create empty slots in the layout data for each child slot the component has
    if (payload.component?.metadata?.slots) {
      Object.keys(payload.component.metadata.slots).forEach((name) => {
        children.push({
          uuid: `-slot-${name}`,
          name: name,
          nodeType: 'slot',
          children: [],
        });
      });
    }

    const layoutModel: RootLayoutModel = {
      layout: {
        children: [
          {
            children,
            nodeType: 'component',
            type: payload.component.id,
            uuid: uuid,
          },
        ],
        nodeType: 'root',
        uuid: 'root',
      },
      model: {
        [uuid]: initialData,
      },
    };

    dispatch(
      insertNodes({
        to: payload.to,
        layoutModel,
        useUUID: uuid,
      }),
    );
    dispatch(setSelectedComponent(uuid));
  };

export const addNewSectionToLayout =
  (payload: AddNewSectionPayload) => (dispatch: AppDispatch) => {
    const uuid = uuidv4();

    const { to, layoutModel } = payload;

    if (!to || !layoutModel) {
      return;
    }

    dispatch(
      insertNodes({
        to,
        layoutModel,
        useUUID: uuid,
      }),
    );
    dispatch(setSelectedComponent(uuid));
  };

// Action creators are generated for each case reducer function.
export const {
  deleteNode,
  setLayoutModel,
  duplicateNode,
  moveNode,
  shiftNode,
  sortNode,
  insertNodes,
  updateNodeModel,
  updateNodeModelForce,
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
