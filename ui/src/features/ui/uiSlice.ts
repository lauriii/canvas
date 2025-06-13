import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';
import { createSelector } from '@reduxjs/toolkit';

export interface DraggingStatus {
  isDragging: boolean;
  treeDragging: boolean;
  listDragging: boolean;
  previewDragging: boolean;
}

export interface CanvasViewPort {
  x: number;
  y: number;
  scale: number;
}

export interface Selection {
  consecutive: boolean;
  items: string[];
}

export const DEFAULT_REGION = 'content' as const;

export enum CanvasMode {
  INTERACTIVE = 'interactive',
  EDIT = 'edit',
}

export type UndoRedoType = 'layoutModel' | 'pageData';

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
  canvasViewport: CanvasViewPort;
  latestUndoRedoActionId: string;
  firstLoadComplete: boolean;
  canvasMode: CanvasMode;
  undoStack: Array<UndoRedoType>;
  redoStack: Array<UndoRedoType>;
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
  },
  panning: false,
  hoveredComponent: undefined,
  updatingComponent: undefined,
  targetSlot: undefined,
  viewportWidth: 0,
  viewportMinHeight: 0,
  canvasViewport: {
    x: 0,
    y: 0,
    scale: 1,
  },
  undoStack: [],
  redoStack: [],
  latestUndoRedoActionId: '',
  firstLoadComplete: false,
  canvasMode: CanvasMode.EDIT,
  selection: {
    consecutive: false,
    items: [],
  },
  collapsedLayers: [],
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
    pushUndo: create.reducer((state, action: PayloadAction<UndoRedoType>) => {
      state.undoStack.push(action.payload);
      state.redoStack = [];
    }),
    performUndoOrRedo: create.reducer(
      // Take care of moving undo/redo types:
      // * from the undo stack to the redo stack in the case of an UNDO action;
      // * from the redo stack to the undo stack in the case of a REDO action.
      (state, action: PayloadAction<boolean>) => {
        const isUndo = action.payload;
        const undoStack = [...state.undoStack];
        const redoStack = [...state.redoStack];
        if (isUndo && undoStack.length > 0) {
          redoStack.unshift(undoStack.pop() as UndoRedoType);
          return { ...state, undoStack, redoStack };
        }
        // Move the last redo state into the undo stack.
        if (redoStack.length > 0) {
          undoStack.push(redoStack.shift() as UndoRedoType);
        }
        return { ...state, undoStack, redoStack };
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
    setCanvasViewPort: create.reducer(
      (state, action: PayloadAction<UpdateViewportPayload>) => {
        if (action.payload.x !== undefined) {
          state.canvasViewport.x = action.payload.x;
        }
        if (action.payload.y !== undefined) {
          state.canvasViewport.y = action.payload.y;
        }
        state.canvasViewport.scale =
          action.payload.scale || state.canvasViewport.scale;
      },
    ),
    canvasViewPortZoomIn: create.reducer((state, action) => {
      const currentScale = state.canvasViewport.scale;
      const newIndex = getNewScaleIndex(currentScale, 'increment');
      state.canvasViewport.scale = scaleValues[newIndex].scale;
    }),
    canvasViewPortZoomOut: create.reducer((state, action) => {
      const currentScale = state.canvasViewport.scale;
      const newIndex = getNewScaleIndex(currentScale, 'decrement');
      state.canvasViewport.scale = scaleValues[newIndex].scale;
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
    setCanvasModeEditing: create.reducer((state) => {
      state.canvasMode = CanvasMode.EDIT;
    }),
    setCanvasModeInteractive: create.reducer((state) => {
      state.canvasMode = CanvasMode.INTERACTIVE;
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
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectUndoType: (ui): UndoRedoType | undefined =>
      ui.undoStack[ui.undoStack.length - 1] || undefined,
    selectRedoType: (ui): UndoRedoType | undefined =>
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
    selectCanvasViewPort: (ui): CanvasViewPort => {
      return ui.canvasViewport;
    },
    selectCanvasViewPortScale: (ui): number => {
      return ui.canvasViewport.scale;
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
    selectCanvasMode: (ui): CanvasMode => {
      return ui.canvasMode;
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
  },
});

// Action creators are generated for each case reducer function.
export const {
  setPending,
  setTreeDragging,
  setPreviewDragging,
  setListDragging,
  setIsPanning,
  setIsZooming,
  setHoveredComponent,
  setUpdatingComponent,
  setTargetSlot,
  unsetHoveredComponent,
  unsetUpdatingComponent,
  unsetTargetSlot,
  setCanvasViewPort,
  canvasViewPortZoomIn,
  canvasViewPortZoomOut,
  setViewportWidth,
  setViewportMinHeight,
  setLatestUndoRedoActionId,
  setFirstLoadComplete,
  setCanvasModeEditing,
  setCanvasModeInteractive,
  pushUndo,
  performUndoOrRedo,
  clearSelection,
  setSelection,
  setCollapsedLayers,
  toggleCollapsedLayer,
  removeCollapsedLayers,
} = uiSlice.actions;

export const {
  selectDragging,
  selectPanning,
  selectZooming,
  selectHoveredComponent,
  selectNoComponentIsHovered,
  selectTargetSlot,
  selectCanvasViewPort,
  selectCanvasViewPortScale,
  selectViewportWidth,
  selectViewportMinHeight,
  selectLatestUndoRedoActionId,
  selectFirstLoadComplete,
  selectCanvasMode,
  selectUndoType,
  selectRedoType,
  selectSelection,
  selectIsMultiSelect,
  selectCollapsedLayers,
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
