import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

export interface DraggingStatus {
  isDragging: boolean;
  treeDragging: boolean;
  listDragging: boolean;
  previewDragging: boolean;
}

export interface PanningStatus {
  isPanning: boolean;
}

export interface CanvasViewPort {
  x: number;
  y: number;
  scale: number;
}

export const DEFAULT_REGION = 'content' as const;

export enum CanvasMode {
  INTERACTIVE = 'interactive',
  EDIT = 'edit',
}

export type UndoRedoType = 'layoutModel' | 'pageData';

export interface uiSliceState {
  pending: boolean;
  dragging: DraggingStatus;
  panning: PanningStatus;
  readOnlySelectedComponent: string | undefined;
  hoveredComponent: string | undefined; //uuid of component
  targetSlot: string | undefined; //uuid of slot being hovered when dragging
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
  dragging: {
    isDragging: false,
    treeDragging: false,
    listDragging: false,
    previewDragging: false,
  },
  panning: {
    isPanning: false,
  },
  readOnlySelectedComponent: undefined,
  hoveredComponent: undefined,
  targetSlot: undefined,
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
};

interface ScaleValue {
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
    _setReadOnlySelectedComponent: create.reducer(
      (state, action: PayloadAction<string | undefined>) => {
        // Store the selected component ID (readOnlySelectedComponent) in Redux to allow Hyperscriptify
        // and extensions to access the value from the store (they are unable to access the value from the Router).
        // Updated when the URL params are changed but setting it from elsewhere will NOT update the URL!
        state.readOnlySelectedComponent = action.payload;
      },
    ),
    setIsPanning: create.reducer((state, action: PayloadAction<boolean>) => {
      state.panning.isPanning = action.payload;
    }),
    setHoveredComponent: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.hoveredComponent = action.payload;
      },
    ),
    setTargetSlot: create.reducer((state, action: PayloadAction<string>) => {
      state.targetSlot = action.payload;
    }),
    unsetHoveredComponent: create.reducer((state) => {
      state.hoveredComponent = undefined;
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
    canvasViewPortZoomDelta: create.reducer(
      (state, action: PayloadAction<number>) => {
        if (action.payload) {
          state.canvasViewport.scale = Math.max(
            Math.min(state.canvasViewport.scale - action.payload / 100, 5),
            0.25,
          );
          return;
        }
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
    setCanvasModeEditing: create.reducer((state, action) => {
      state.canvasMode = CanvasMode.EDIT;
    }),
    setCanvasModeInteractive: create.reducer((state, action) => {
      state.canvasMode = CanvasMode.INTERACTIVE;
    }),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectUndoType: (ui): UndoRedoType | undefined =>
      ui.undoStack[ui.undoStack.length - 1] || undefined,
    selectRedoType: (ui): UndoRedoType | undefined =>
      ui.redoStack[0] || undefined,
    selectPanning: (ui): PanningStatus => {
      return ui.panning;
    },
    selectDragging: (ui): DraggingStatus => {
      return ui.dragging;
    },
    selectHoveredComponent: (ui): string | undefined => {
      return ui.hoveredComponent;
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
    selectLatestUndoRedoActionId: (ui): string => {
      return ui.latestUndoRedoActionId;
    },
    selectFirstLoadComplete: (ui): boolean => {
      return ui.firstLoadComplete;
    },
    selectCanvasMode: (ui): CanvasMode => {
      return ui.canvasMode;
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
  _setReadOnlySelectedComponent,
  setHoveredComponent,
  setTargetSlot,
  unsetHoveredComponent,
  unsetTargetSlot,
  setCanvasViewPort,
  canvasViewPortZoomIn,
  canvasViewPortZoomOut,
  canvasViewPortZoomDelta,
  setLatestUndoRedoActionId,
  setFirstLoadComplete,
  setCanvasModeEditing,
  setCanvasModeInteractive,
  pushUndo,
  performUndoOrRedo,
} = uiSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectDragging,
  selectPanning,
  selectHoveredComponent,
  selectTargetSlot,
  selectCanvasViewPort,
  selectCanvasViewPortScale,
  selectLatestUndoRedoActionId,
  selectFirstLoadComplete,
  selectCanvasMode,
  selectUndoType,
  selectRedoType,
} = uiSlice.selectors;

export const uiSliceReducer = uiSlice.reducer;

export const UndoRedoActionCreators = {
  undo: (type: UndoRedoType) => ({ type: `@@redux-undo/${type}_UNDO` }),
  redo: (type: UndoRedoType) => ({ type: `@@redux-undo/${type}_REDO` }),
};
