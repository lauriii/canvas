// cspell:ignore uuidv
import type { AppDispatch, RootState } from '@/app/store';
import type { Component } from '@/types/Component';
import { componentHasFieldData } from '@/types/Component';
import type { UUID } from '@/types/UUID';
import type { PayloadAction } from '@reduxjs/toolkit';
import { createSelector } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import type { StateWithHistory } from 'redux-undo';
import { v4 as uuidv4 } from 'uuid';
import { setXbDrupalSetting } from '@/features/drupal/drupalUtil';
import {
  findComponentByUuid,
  findNodePathByUuid,
  insertNodeAtPath,
  moveNodeToPath,
  recurseNodes,
  removeComponentByUuid,
  replaceUUIDsAndUpdateModel,
} from './layoutUtils';

export enum NodeType {
  Region = 'region',
  Component = 'component',
  Slot = 'slot',
}

export interface RegionNode {
  name: string;
  id: string;
  nodeType: NodeType.Region;
  components: ComponentNode[];
}

export interface ComponentNode {
  nodeType: NodeType.Component;
  uuid: UUID;
  type: string;
  slots: SlotNode[];
}

export interface SlotNode {
  nodeType: NodeType.Slot;
  id: string;
  name: string;
  components: ComponentNode[];
}

export type LayoutNode = RegionNode | ComponentNode | SlotNode;
export type LayoutChildNode = ComponentNode | SlotNode;

export interface RootLayoutModel {
  layout: Array<RegionNode>;
  model: ComponentModels;
}

export interface LayoutModelPiece {
  layout: ComponentNode[];
  model: ComponentModels;
}

export type ComponentModels = Record<string, ComponentModel>;

export interface LayoutModelSliceState extends RootLayoutModel {
  initialized: boolean;
}

export const initialState: LayoutModelSliceState = {
  layout: [
    {
      nodeType: NodeType.Region,
      name: 'content',
      components: [],
      id: 'content',
    },
  ],
  model: {},
  // @todo should this be renamed to 'updatePreview' as that seems to be the
  // only use case at this point. https://www.drupal.org/project/experience_builder/issues/3504983
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
  layoutModel: LayoutModelPiece;
  /**
   * Pass an optional UUID that will be assigned to the last, top level node being inserted. Allows you to define the UUID
   * so that you can then do something with the newly inserted node using that UUID.
   */
  useUUID?: string;
};

type AddNewNodePayload = {
  to: number[];
  component: Component;
};

type AddNewSectionPayload = {
  to: number[] | undefined;
  layoutModel: LayoutModelPiece;
};

type SortNodePayload = {
  uuid: string | undefined;
  to: number | undefined;
};

type AnyValue = string | boolean | [] | number | {} | null;

// @see \Drupal\experience_builder\PropSource\PropSource::parse()
interface BasePropSource {
  sourceType: string;
}
// @see \Drupal\experience_builder\PropSource\DynamicPropSource
export interface DynamicPropSource extends BasePropSource {
  expression: string;
}

// @see \Drupal\experience_builder\PropSource\StaticPropSource
export interface StaticPropSource extends BasePropSource {
  expression: string;
  // This can be omitted if it duplicates the resolved value. There are some
  // scenarios where the resolved value will differ from the source value, e.g.
  // a media reference - in that case the source value will be the target ID,
  // whilst the resolved value will be the image URI or similar.
  value?: AnyValue;
  sourceTypeSettings: Record<string, AnyValue>;
}

// @see \Drupal\experience_builder\PropSource\AdaptedPropSource
export interface AdaptedPropSource extends BasePropSource {
  adapterInputs: Record<string, PropSource>;
}

export type PropSource =
  | AdaptedPropSource
  | StaticPropSource
  | DynamicPropSource;

export type ResolvedValues = Record<string, AnyValue>;

export interface ComponentModel {
  name: string;
  // The props that are used to render previews and will be used for client-side
  // preview updates (when they're supported).
  resolved: ResolvedValues;
}

export type Sources = Record<string, PropSource>;

export interface EvaluatedComponentModel extends ComponentModel {
  // Source props/expressions needed by the server.
  source: Sources;
}

export const isEvaluatedComponentModel = (
  model: ComponentModel,
): model is EvaluatedComponentModel => {
  return 'source' in model;
};

export const layoutModelSlice = createSlice({
  name: 'layoutModel',
  initialState,
  reducers: (create) => ({
    deleteNode: create.reducer((state, action: PayloadAction<string>) => {
      const deletedComponent = findComponentByUuid(
        state.layout,
        action.payload,
      );

      const removableModelsUuids = [action.payload];
      if (deletedComponent) {
        recurseNodes(deletedComponent, (node: ComponentNode) => {
          removableModelsUuids.push(node.uuid);
        });
      }
      for (const uuid of removableModelsUuids) {
        if (state.model[uuid]) delete state.model[uuid];
      }

      state.layout = removeComponentByUuid(state.layout, action.payload);
      // Flag a preview update.
      state.initialized = true;
    }),
    duplicateNode: create.reducer(
      (state, action: PayloadAction<DuplicateNodePayload>) => {
        const { uuid } = action.payload;
        const nodeToDuplicate = findComponentByUuid(state.layout, uuid);

        if (!nodeToDuplicate) {
          console.error(`Cannot duplicate ${uuid}. Check the uuid is valid.`);
          return;
        }

        if (nodeToDuplicate.nodeType !== 'component') {
          console.error(
            `Cannot duplicate Slots or Regions. Check the uuid ${uuid} is a valid Component.`,
          );
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
        const rootIndex = nodePath.shift();
        if (rootIndex === undefined) {
          throw new Error(
            'Path should be at least two items long, starting from the root region',
          );
        }
        const root = state.layout[rootIndex];
        const newState = state.layout;
        newState[rootIndex] = insertNodeAtPath(
          root,
          nodePath,
          updatedNode,
        ) as RegionNode;
        state.layout = newState;
        // Flag a preview update.
        state.initialized = true;
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
        // Flag a preview update.
        state.initialized = true;
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
        let newLayout: Array<RegionNode> = JSON.parse(
          JSON.stringify(state.layout),
        );
        const components = layoutModel.layout;
        const model = layoutModel.model;

        const rootIndex = to.shift();
        if (rootIndex === undefined) {
          throw new Error(
            'Path should be at least two items long, starting from the root region',
          );
        }
        let regionRoot = newLayout[rootIndex];

        // Loop through each node in reverse order to maintain the correct insert positions
        for (let i = components.length - 1; i >= 0; i--) {
          const node = components[i];
          const specifyUUID = i === 0;
          const { updatedNode, updatedModel: nodeUpdatedModel } =
            replaceUUIDsAndUpdateModel(
              node,
              model,
              specifyUUID ? useUUID : undefined,
            );
          updatedModel = { ...updatedModel, ...nodeUpdatedModel };
          regionRoot = insertNodeAtPath(regionRoot, to, updatedNode);
        }

        state.model = updatedModel;
        state.layout[rootIndex] = regionRoot;
        // Flag a preview update.
        state.initialized = true;
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

        const cloneNode = JSON.parse(
          JSON.stringify(findComponentByUuid(state.layout, uuid)),
        );
        const nodePath = findNodePathByUuid(state.layout, uuid);
        const rootIndex = nodePath?.shift() || undefined;
        if (rootIndex === undefined) {
          throw new Error(
            'Path should be at least two items long, starting from the root region',
          );
        }
        if (cloneNode && nodePath) {
          const insertPosition = [...nodePath.slice(0, -1), to];
          const newLayout = removeComponentByUuid(state.layout, uuid);

          state.layout[rootIndex] = insertNodeAtPath(
            newLayout[rootIndex],
            insertPosition,
            cloneNode,
          );
          // Flag a preview update.
          state.initialized = true;
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

        const cloneNode = JSON.parse(
          JSON.stringify(findComponentByUuid(state.layout, uuid)),
        );
        const nodePath = findNodePathByUuid(state.layout, uuid);
        const rootIndex = nodePath?.shift() || undefined;
        if (rootIndex === undefined) {
          throw new Error(
            'Path should be at least two items long, starting from the root region',
          );
        }
        if (cloneNode && nodePath) {
          const newPos =
            direction === 'down'
              ? nodePath[nodePath.length - 1] + 1
              : Math.max(0, nodePath[nodePath.length - 1] - 1);
          const insertPosition = [...nodePath.slice(0, -1), newPos];
          const newLayout = removeComponentByUuid(state.layout, uuid);

          state.layout[rootIndex] = insertNodeAtPath(
            newLayout[rootIndex],
            insertPosition,
            cloneNode,
          );
          // Flag a preview update.
          state.initialized = true;
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
  }),
});

export const addNewComponentToLayout =
  (payload: AddNewNodePayload, setSelectedComponent: Function) =>
  (dispatch: AppDispatch) => {
    const { to, component } = payload;
    // Populate the model data with the default values
    const buildInitialData = (component: Component): ComponentModel => {
      if (componentHasFieldData(component)) {
        const initialData: EvaluatedComponentModel = {
          name: component.name,
          resolved: {},
          source: {},
        };
        Object.keys(component.field_data).forEach((propName) => {
          const prop = component.field_data[propName];
          // These will be needed when we support client-side preview updates.
          // @todo default values will be split into resolved/source in https://www.drupal.org/i/3493943, this will use the resolved value when that occurs.
          initialData.resolved[propName] = prop.default_values;
          // These are the values the server needs.
          initialData.source[propName] = {
            expression: prop.expression,
            sourceType: prop.sourceType,
            // @todo omit this in https://www.drupal.org/i/3493943 if it matches the resolved default value
            value: prop.default_values,
            // @todo Consider omitting this in https://www.drupal.org/i/3463996, to send less data.
            sourceTypeSettings: prop.sourceTypeSettings || undefined,
          };
        });
        return initialData;
      }
      return {
        name: component.name,
        resolved: {},
      };
    };

    const slots: SlotNode[] = [];
    const uuid = uuidv4();

    if (componentHasFieldData(component)) {
      // Create empty slots in the layout data for each child slot the component
      // has.
      Object.keys(component.metadata.slots || []).forEach((name) => {
        slots.push({
          id: `${uuid}/${name}`,
          name: name,
          nodeType: NodeType.Slot,
          components: [],
        });
      });
    }

    const layoutModel: LayoutModelPiece = {
      layout: [
        {
          slots,
          nodeType: NodeType.Component,
          type: component.id,
          uuid: uuid,
        },
      ],
      model: {
        [uuid]: buildInitialData(component),
      },
    };

    dispatch(
      insertNodes({
        to,
        layoutModel,
        useUUID: uuid,
      }),
    );
    setSelectedComponent(uuid);
  };

export const addNewSectionToLayout =
  (payload: AddNewSectionPayload, setSelectedComponent: Function) =>
  (dispatch: AppDispatch) => {
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
    setSelectedComponent(uuid);
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
} = layoutModelSlice.actions;

export const layoutModelReducer = layoutModelSlice.reducer;

// When using redux-undo, you reference the current state by state.[sliceName].present.[targetKey].
// These selectors are written outside the slice because the type of state is different. Here, we need
// to be able to access the history, so we use the StateWithHistoryWrapper type.
export const selectLayout = (state: StateWithHistoryWrapper) =>
  state.layoutModel.present.layout;
export const selectModel = (state: StateWithHistoryWrapper) =>
  state.layoutModel.present.model;
export const selectLayoutHistory = (state: StateWithHistoryWrapper) =>
  state.layoutModel;
export const selectLayoutInitialized = (state: StateWithHistoryWrapper) =>
  state.layoutModel.present.initialized;
const selectRegion = (state: RootState, regionName: string) => regionName;

export const selectLayoutForRegion = createSelector(
  [selectLayout, selectRegion],
  (layout: Array<RegionNode>, regionName: string) =>
    layout.find((region) => region.id === regionName) ||
    ({
      components: [],
      name: regionName,
      id: regionName,
      nodeType: 'region',
    } as RegionNode),
);

// Add some of the functionality offered here to drupalSettings, so extensions
// can use it.
const layoutUtils = {
  addNewComponentToLayout,
  addNewSectionToLayout,
  selectLayoutForRegion,
};
setXbDrupalSetting('layoutUtils', layoutUtils);
