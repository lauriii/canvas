import { createSelector } from '@reduxjs/toolkit';

import { createAppSlice } from '@/app/createAppSlice';

import type { Action, PayloadAction } from '@reduxjs/toolkit';

export interface DraggingStatus {
  isDragging: boolean;
  treeDragging: boolean;
  listDragging: boolean;
  previewDragging: boolean;
  codeDragging: boolean;
}

export interface EditorViewPort {
  x: number;
  y: number;
  scale: number;
}

export interface PreviouslyEdited {
  name: string;
  path: string;
}

export interface Selection {
  consecutive: boolean;
  items: string[];
}

export const DEFAULT_REGION = 'content' as const;

export enum EditorFrameMode {
  INTERACTIVE = 'interactive',
  EDIT = 'edit',
}

export enum EditorFrameContext {
  ENTITY = 'entity',
  TEMPLATE = 'template',
  NONE = 'none',
}

export type UndoRedoType = 'layoutModel' | 'pageData';

export interface RouteSnapshot {
  pathname: string;
  search: string;
  hash: string;
}

export interface UndoRedoStackItem {
  targetSlice: UndoRedoType;
  routeSnapshot: RouteSnapshot;
  debugInfoAction?: Action;
}

export interface uiSliceState {
  pending: boolean;
  zooming: boolean;
  dragging: DraggingStatus;
  panning: boolean;
  hoveredComponent: string | undefined; //uuid of component
  updatingComponent: string | undefined; //uuid of component
  selection: Selection;
  collapsedLayers: string[];
  targetSlot: string | undefined; //uuid of slot being hovered when dragging
  viewportWidth: number;
  viewportMinHeight: number;
  editorViewPort: EditorViewPort;
  latestUndoRedoActionId: string;
  firstLoadComplete: boolean;
  editorFrameMode: EditorFrameMode;
  editorFrameContext: EditorFrameContext;
  undoStack: Array<UndoRedoStackItem>;
  redoStack: Array<UndoRedoStackItem>;
  currentRoute: RouteSnapshot;
  PreviouslyEdited: PreviouslyEdited;
}

type UpdateViewportPayload = {
  x?: number | undefined;
  y?: number | undefined;
  scale?: number | undefined;
};

export const initialState: uiSliceState = {
  pending: false,
  zooming: false,
  dragging: {
    isDragging: false,
    treeDragging: false,
    listDragging: false,
    previewDragging: false,
    codeDragging: false,
  },
  panning: false,
  hoveredComponent: undefined,
  updatingComponent: undefined,
  targetSlot: undefined,
  viewportWidth: 0,
  viewportMinHeight: 0,
  editorViewPort: {
    x: 0,
    y: 0,
    scale: 1,
  },
  undoStack: [],
  redoStack: [],
  currentRoute: {
    pathname: '',
    search: '',
    hash: '',
  },
  latestUndoRedoActionId: '',
  firstLoadComplete: false,
  editorFrameMode: EditorFrameMode.EDIT,
  editorFrameContext: EditorFrameContext.NONE,
  selection: {
    consecutive: false,
    items: [],
  },
  collapsedLayers: [],
  PreviouslyEdited: { name: '', path: '' },
};

export interface ScaleValue {
  scale: number;
  percent: string;
}

export const scaleValues: ScaleValue[] = [
  { scale: 0.25, percent: '25%' },
  { scale: 0.33, percent: '33%' },
  { scale: 0.5, percent: '50%' },
  { scale: 0.67, percent: '67%' },
  { scale: 0.75, percent: '75%' },
  { scale: 0.8, percent: '80%' },
  { scale: 0.9, percent: '90%' },
  { scale: 1, percent: '100%' },
  { scale: 1.1, percent: '110%' },
  { scale: 1.25, percent: '125%' },
  { scale: 1.5, percent: '150%' },
  { scale: 1.75, percent: '175%' },
  { scale: 2, percent: '200%' },
  { scale: 2.5, percent: '250%' },
  { scale: 3, percent: '300%' },
  { scale: 4, percent: '400%' },
  { scale: 5, percent: '500%' },
];

/**
 * Get the next/previous closest scale to the current scale (which might not be one of the
 * available scaleValues) up to the min/max scaleValue available.
 */
const getNewScaleIndex = (
  currentScale: number,
  direction: 'increment' | 'decrement',
) => {
  let currentIndex = scaleValues.findIndex(
    (value) => value.scale === currentScale,
  );

  if (currentIndex === -1) {
    currentIndex = scaleValues.findIndex((value) => value.scale > currentScale);
    currentIndex =
      direction === 'increment'
        ? Math.max(0, currentIndex)
        : Math.max(0, currentIndex - 1);
  } else {
    currentIndex += direction === 'increment' ? 1 : -1;
  }

  // Clamp value between 0 and length of scaleValues array.
  return Math.max(0, Math.min(scaleValues.length - 1, currentIndex));
};

// If you are not using async thunks you can use the standalone `createSlice`.
export const uiSlice = createAppSlice({
  name: 'ui',
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: (create) => ({
    pushUndo: create.reducer(
      (state, action: PayloadAction<UndoRedoStackItem>) => {
        state.undoStack.push(action.payload);
        state.redoStack = [];
      },
    ),
    performUndoOrRedo: create.reducer(
      // Take care of moving undo/redo items:
      // * from the undo stack to the redo stack in the case of an UNDO action;
      // * from the redo stack to the undo stack in the case of a REDO action.
      // Also restore the route from the stack item.
      (state, action: PayloadAction<boolean>) => {
        const isUndo = action.payload;
        const undoStack = [...state.undoStack];
        const redoStack = [...state.redoStack];
        let routeToRestore: RouteSnapshot | null = null;

        if (isUndo && undoStack.length > 0) {
          const undoItem = undoStack.pop() as UndoRedoStackItem;
          redoStack.unshift(undoItem);
          routeToRestore = undoItem.routeSnapshot;
        } else if (redoStack.length > 0) {
          // Move the last redo state into the undo stack.
          const redoItem = redoStack.shift() as UndoRedoStackItem;
          undoStack.push(redoItem);
          routeToRestore = redoItem.routeSnapshot;
        }

        return {
          ...state,
          undoStack,
          redoStack,
          currentRoute: routeToRestore || state.currentRoute,
        };
      },
    ),
    setPending: create.reducer((state, action: PayloadAction<boolean>) => {
      state.pending = action.payload;
    }),
    setTreeDragging: create.reducer((state, action: PayloadAction<boolean>) => {
      state.dragging.isDragging = action.payload;
      state.dragging.treeDragging = action.payload;
    }),
    setPreviewDragging: create.reducer(
      (state, action: PayloadAction<boolean>) => {
        state.dragging.isDragging = action.payload;
        state.dragging.previewDragging = action.payload;
      },
    ),
    setListDragging: create.reducer((state, action: PayloadAction<boolean>) => {
      state.dragging.isDragging = action.payload;
      state.dragging.listDragging = action.payload;
    }),
    setCodeDragging: create.reducer((state, action: PayloadAction<boolean>) => {
      state.dragging.isDragging = action.payload;
      state.dragging.codeDragging = action.payload;
    }),
    setIsPanning: create.reducer((state, action: PayloadAction<boolean>) => {
      state.panning = action.payload;
    }),
    setIsZooming: create.reducer((state, action: PayloadAction<boolean>) => {
      state.zooming = action.payload;
    }),
    setHoveredComponent: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.hoveredComponent = action.payload;
      },
    ),
    setUpdatingComponent: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.updatingComponent = action.payload;
      },
    ),
    setTargetSlot: create.reducer((state, action: PayloadAction<string>) => {
      state.targetSlot = action.payload;
    }),
    unsetHoveredComponent: create.reducer((state) => {
      state.hoveredComponent = undefined;
    }),
    unsetUpdatingComponent: create.reducer((state) => {
      state.updatingComponent = undefined;
    }),
    unsetTargetSlot: create.reducer((state) => {
      state.targetSlot = undefined;
    }),
    setEditorFrameViewPort: create.reducer(
      (state, action: PayloadAction<UpdateViewportPayload>) => {
        if (action.payload.x !== undefined) {
          state.editorViewPort.x = action.payload.x;
        }
        if (action.payload.y !== undefined) {
          state.editorViewPort.y = action.payload.y;
        }
        state.editorViewPort.scale =
          action.payload.scale || state.editorViewPort.scale;
      },
    ),
    editorViewPortZoomIn: create.reducer((state, action) => {
      const currentScale = state.editorViewPort.scale;
      const newIndex = getNewScaleIndex(currentScale, 'increment');
      state.editorViewPort.scale = scaleValues[newIndex].scale;
    }),
    editorViewPortZoomOut: create.reducer((state, action) => {
      const currentScale = state.editorViewPort.scale;
      const newIndex = getNewScaleIndex(currentScale, 'decrement');
      state.editorViewPort.scale = scaleValues[newIndex].scale;
    }),
    setViewportWidth: create.reducer((state, action: PayloadAction<number>) => {
      state.viewportWidth = action.payload;
    }),
    setViewportMinHeight: create.reducer(
      (state, action: PayloadAction<number>) => {
        state.viewportMinHeight = action.payload;
      },
    ),
    setLatestUndoRedoActionId: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.latestUndoRedoActionId = action.payload;
      },
    ),
    setFirstLoadComplete: create.reducer(
      (state, action: PayloadAction<boolean>) => {
        state.firstLoadComplete = action.payload;
      },
    ),
    setEditorFrameModeEditing: create.reducer((state) => {
      state.editorFrameMode = EditorFrameMode.EDIT;
    }),
    setEditorFrameModeInteractive: create.reducer((state) => {
      state.editorFrameMode = EditorFrameMode.INTERACTIVE;
    }),
    setEditorFrameContext: create.reducer(
      (state, action: PayloadAction<EditorFrameContext>) => {
        state.editorFrameContext = action.payload;
      },
    ),
    unsetEditorFrameContext: create.reducer((state) => {
      state.editorFrameContext = EditorFrameContext.NONE;
    }),
    clearSelection: create.reducer((state) => {
      state.selection.items.length = 0;
    }),
    setSelection: create.reducer(
      (
        state,
        action: PayloadAction<{ items: string[]; consecutive?: boolean }>,
      ) => {
        state.selection.items = [...action.payload.items];
        if (action.payload.items.length <= 1) {
          // if there is only one (or no) items selected, then consecutive is always true.
          state.selection.consecutive = true;
        } else {
          state.selection.consecutive = action.payload.consecutive || false;
        }
      },
    ),
    setPreviouslyEdited: create.reducer(
      (state, action: PayloadAction<PreviouslyEdited>) => {
        state.PreviouslyEdited = action.payload;
      },
    ),
    unsetPreviouslyEdited: create.reducer((state) => {
      state.PreviouslyEdited = { name: '', path: '' };
    }),
    setCollapsedLayers: (state, action: PayloadAction<string[]>) => {
      state.collapsedLayers = action.payload;
    },
    toggleCollapsedLayer: (state, action: PayloadAction<string>) => {
      const index = state.collapsedLayers.indexOf(action.payload);
      if (index >= 0) {
        state.collapsedLayers.splice(index, 1);
      } else {
        state.collapsedLayers.push(action.payload);
      }
    },
    removeCollapsedLayers: (state, action: PayloadAction<string[]>) => {
      const uuidsToRemove = new Set(action.payload);
      state.collapsedLayers = state.collapsedLayers.filter(
        (uuid) => !uuidsToRemove.has(uuid),
      );
    },
    setCurrentRoute: create.reducer(
      (state, action: PayloadAction<RouteSnapshot>) => {
        state.currentRoute = action.payload;
      },
    ),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectUndoItem: (ui): UndoRedoStackItem | undefined =>
      ui.undoStack[ui.undoStack.length - 1] || undefined,
    selectRedoItem: (ui): UndoRedoStackItem | undefined =>
      ui.redoStack[0] || undefined,
    selectPanning: (ui): boolean => {
      return ui.panning;
    },
    selectZooming: (ui): boolean => {
      return ui.zooming;
    },
    selectDragging: (ui): DraggingStatus => {
      return ui.dragging;
    },
    selectHoveredComponent: (ui): string | undefined => {
      return ui.hoveredComponent;
    },
    selectIsComponentHovered: (ui, uuid): boolean => {
      return ui.hoveredComponent === uuid;
    },
    selectNoComponentIsHovered: (ui): boolean => {
      return ui.hoveredComponent === undefined;
    },
    selectTargetSlot: (ui): string | undefined => {
      return ui.targetSlot;
    },
    selectEditorViewPort: (ui): EditorViewPort => {
      return ui.editorViewPort;
    },
    selectEditorViewPortScale: (ui): number => {
      return ui.editorViewPort.scale;
    },
    selectViewportWidth: (ui): number => {
      return ui.viewportWidth;
    },
    selectViewportMinHeight: (ui): number => {
      return ui.viewportMinHeight;
    },
    selectLatestUndoRedoActionId: (ui): string => {
      return ui.latestUndoRedoActionId;
    },
    selectFirstLoadComplete: (ui): boolean => {
      return ui.firstLoadComplete;
    },
    selectEditorFrameMode: (ui): EditorFrameMode => {
      return ui.editorFrameMode;
    },
    selectEditorFrameContext: (ui): EditorFrameContext => {
      return ui.editorFrameContext;
    },
    selectSelection: (ui): Selection => {
      return ui.selection;
    },
    selectIsMultiSelect: (ui): boolean => {
      // True when there are multiple components selected
      return ui.selection.items.length > 1;
    },
    selectIsSingleSelect: (ui): boolean => {
      // True when there's exactly one component selected
      return ui.selection.items.length === 1;
    },
    selectSelectedComponentUuid: (ui): string | undefined => {
      // Returns the selected component ID when in single-select mode
      // Returns undefined when in multi-select mode
      return ui.selection.items.length === 1
        ? ui.selection.items[0]
        : undefined;
    },
    selectCollapsedLayers: (ui): string[] => {
      return ui.collapsedLayers;
    },
    selectPreviouslyEdited: (ui): PreviouslyEdited => {
      return ui.PreviouslyEdited;
    },
    selectCurrentRoute: (ui): RouteSnapshot => {
      return ui.currentRoute;
    },
  },
});

// Action creators are generated for each case reducer function.
export const {
  setPending,
  setTreeDragging,
  setPreviewDragging,
  setListDragging,
  setCodeDragging,
  setIsPanning,
  setIsZooming,
  setHoveredComponent,
  setUpdatingComponent,
  setTargetSlot,
  unsetHoveredComponent,
  unsetUpdatingComponent,
  unsetTargetSlot,
  setEditorFrameViewPort,
  editorViewPortZoomIn,
  editorViewPortZoomOut,
  setViewportWidth,
  setViewportMinHeight,
  setLatestUndoRedoActionId,
  setFirstLoadComplete,
  setEditorFrameModeEditing,
  setEditorFrameModeInteractive,
  setEditorFrameContext,
  unsetEditorFrameContext,
  pushUndo,
  performUndoOrRedo,
  clearSelection,
  setSelection,
  setPreviouslyEdited,
  unsetPreviouslyEdited,
  setCollapsedLayers,
  toggleCollapsedLayer,
  removeCollapsedLayers,
  setCurrentRoute,
} = uiSlice.actions;

export const {
  selectDragging,
  selectPanning,
  selectZooming,
  selectHoveredComponent,
  selectNoComponentIsHovered,
  selectTargetSlot,
  selectEditorViewPort,
  selectEditorViewPortScale,
  selectViewportWidth,
  selectViewportMinHeight,
  selectLatestUndoRedoActionId,
  selectFirstLoadComplete,
  selectEditorFrameMode,
  selectEditorFrameContext,
  selectUndoItem,
  selectRedoItem,
  selectSelection,
  selectIsMultiSelect,
  selectCollapsedLayers,
  selectPreviouslyEdited,
  selectCurrentRoute,
} = uiSlice.selectors;

// Memoized selectors using createSelector for better performance
// These selectors only recompute when their inputs change

/**
 * Checks if a component is selected
 * @param state Redux state
 * @param componentId ID of the component to check
 * @returns boolean indicating if the component is selected
 */
export const selectComponentIsSelected = createSelector(
  [
    (state: { ui: uiSliceState }) => state.ui.selection.items,
    (_: any, componentId: string) => componentId,
  ],
  (items: string[], componentId: string): boolean =>
    items.includes(componentId),
);

/**
 * Checks if a component is currently hovered
 * @param state Redux state
 * @param uuid ID of the component to check
 * @returns boolean indicating if the component is hovered
 */
export const selectIsComponentHovered = createSelector(
  [
    (state: { ui: uiSliceState }) => state.ui.hoveredComponent,
    (_: any, uuid: string) => uuid,
  ],
  (hoveredComponent: string | undefined, uuid: string): boolean =>
    hoveredComponent === uuid,
);

/**
 * Checks if a component is currently updating
 * @param state Redux state
 * @param uuid ID of the component to check
 * @returns boolean indicating if the component is hovered
 */
export const selectIsComponentUpdating = createSelector(
  [
    (state: { ui: uiSliceState }) => state.ui.updatingComponent,
    (_: any, uuid: string) => uuid,
  ],
  (updatingComponent: string | undefined, uuid: string): boolean =>
    updatingComponent === uuid,
);

/**
 * Gets the UUID of the selected component when in single-select mode
 * @param state Redux state
 * @returns The UUID of the selected component or undefined if none or multiple selected
 */
export const selectSelectedComponentUuid = createSelector(
  [(state: { ui: uiSliceState }) => state.ui.selection.items],
  (items: string[]): string | undefined =>
    items.length === 1 ? items[0] : undefined,
);

export const uiSliceReducer = uiSlice.reducer;

export const UndoRedoActionCreators = {
  undo: (type: UndoRedoType) => ({ type: `@@redux-undo/${type}_UNDO` }),
  redo: (type: UndoRedoType) => ({ type: `@@redux-undo/${type}_REDO` }),
};
