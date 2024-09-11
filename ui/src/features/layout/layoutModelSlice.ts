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
import type { Component } from '@/types/Component';

export interface LayoutNode {
  name?: string;
  uuid: UUID;
  nodeType: 'slot' | 'component' | 'root';
  type?: string;
  children: LayoutNode[];
  props?: {} | undefined;
}

export type LayoutNodeWithoutUUID = Omit<LayoutNode, 'uuid'>;

export interface LayoutModelSliceState {
  layout: LayoutNode;
  model: ComponentModels;
  initialized: boolean;
}

type ComponentModels = Record<string, ComponentModel>;

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
  newNode: LayoutNodeWithoutUUID | undefined;
  to: number[] | undefined;
  model: InitialPropData | undefined;
};

type InsertMultipleNodesPayload = {
  newNodes: LayoutNode;
  to: number[] | undefined;
  model: ComponentModels;
};

type AddNewNodePayload = {
  newNode: string | undefined;
  to: number[] | undefined;
  component: Component | undefined;
};

type AddNewSectionPayload = {
  newSection: string | undefined;
  to: number[] | undefined;
  layoutModel: LayoutModelSliceState;
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

/**
 * Replace UUIDs in a layout node and its corresponding model.
 * @param node - The layout node to update.
 * @param model - The corresponding model to update.
 */
const replaceUUIDsAndUpdateModel = (
  node: LayoutNode,
  model: ComponentModels,
) => {
  const oldToNewUUIDMap: Record<string, string> = {};

  const replaceUUIDs = (node: LayoutNode) => {
    if (node.uuid) {
      const newUUID = uuidv4();
      oldToNewUUIDMap[node.uuid] = newUUID;
      node.uuid = newUUID;
    }

    if (node.children) {
      node.children.forEach((child) => replaceUUIDs(child));
    }
  };

  replaceUUIDs(node);

  // Update the model keys
  for (const oldUUID in model) {
    if (oldToNewUUIDMap[oldUUID]) {
      model[oldToNewUUIDMap[oldUUID]] = _.cloneDeep(model[oldUUID]);
      delete model[oldUUID];
    }
  }
};

export const layoutModelSlice = createSlice({
  name: 'layoutModel',
  initialState,
  reducers: (create) => ({
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
        const uuid = uuidv4();
        const childSlotsWithUuids = newNode.children.map((child) => {
          child.uuid = `${uuid}${child.uuid}`;
          return child;
        });

        const newNewNode: LayoutNode = {
          ..._.cloneDeep(newNode),
          children: childSlotsWithUuids,
          uuid,
        };

        state.layout = insertNodeAtPath(state.layout, to, newNewNode);
        state.model[newNewNode.uuid] = {
          ...state.model[newNewNode.uuid],
          ...model,
        };
      },
    ),

    insertMultipleNodes: create.reducer(
      (state, action: PayloadAction<InsertMultipleNodesPayload>) => {
        const { newNodes, to, model } = action.payload;

        if (!newNodes || !newNodes.children.length || !Array.isArray(to)) {
          console.error(
            `Cannot insert nodes. Invalid parameters: newNodes: ${newNodes}, to: ${to}.`,
          );
          return;
        }

        // The nodes we're inserting into the layout already have UUIDs. We need to make sure they're unique before
        // inserting them into the layout., so we need to generate new UUIDs and update. Ww also need the  the model to
        // reflect the new UUIDs.
        const nodesToInsert: LayoutNode[] = _.cloneDeep(newNodes.children);
        const updatedModel: ComponentModels = _.cloneDeep(model);
        let newLayout: LayoutNode = _.cloneDeep(state.layout);

        // Loop backwards so that we don't have to keep incrementing the insert position for each node we insert.
        for (let i = nodesToInsert.length - 1; i >= 0; i--) {
          const node = nodesToInsert[i];
          replaceUUIDsAndUpdateModel(node, updatedModel);
          newLayout = insertNodeAtPath(newLayout, to, node);
        }

        state.model = { ...state.model, ...updatedModel };
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
    if (payload.newNode && payload.to && payload.component) {
      const initialData: InitialPropData = {};
      if (payload.component.field_data) {
        // @todo Update this logic in https://www.drupal.org/project/experience_builder/issues/3455942
        initialData.name = payload.component.name;
        Object.keys(payload.component.field_data).forEach((propName) => {
          if (payload.component?.field_data?.[propName]?.['default_values']) {
            initialData[propName] =
              payload.component?.field_data[propName]['default_values'];
          }
        });
      }

      const children: LayoutNode[] = [];

      if (payload.component.metadata?.slots) {
        Object.keys(payload.component.metadata.slots).forEach((name) => {
          children.push({
            uuid: `-slot-${name}`,
            name: name,
            nodeType: 'slot',
            children: [],
          });
        });
      }

      dispatch(
        insertNode({
          to: payload.to,
          newNode: {
            children,
            nodeType: 'component',
            type: payload.newNode,
          },
          model: initialData,
        }),
      );
    }
  };

export const addNewSectionToLayout =
  (payload: AddNewSectionPayload) => (dispatch: AppDispatch) => {
    if (payload.newSection && payload.to) {
      dispatch(
        insertMultipleNodes({
          to: payload.to,
          newNodes: payload.layoutModel.layout,
          model: payload.layoutModel.model,
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
  insertMultipleNodes,
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
