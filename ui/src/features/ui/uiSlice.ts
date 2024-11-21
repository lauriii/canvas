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

export enum CanvasMode {
  INTERACTIVE = 'interactive',
  EDIT = 'edit',
}

export interface uiSliceState {
  pending: boolean;
  dragging: DraggingStatus;
  panning: PanningStatus;
  selectedComponent: string | undefined; //uuid of component
  hoveredComponent: string | undefined; //uuid of component
  targetSlot: string | undefined; //uuid of slot being hovered when dragging
  canvasViewport: CanvasViewPort;
  latestUndoRedoActionId: string;
  firstLoadComplete: boolean;
  canvasMode: CanvasMode;
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
  selectedComponent: undefined,
  hoveredComponent: undefined,
  targetSlot: undefined,
  canvasViewport: {
    x: 0,
    y: 0,
    scale: 1,
  },
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
      state.panning.isPanning = action.payload;
    }),
    setSelectedComponent: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.selectedComponent = action.payload;
      },
    ),
    setHoveredComponent: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.hoveredComponent = action.payload;
      },
    ),
    setTargetSlot: create.reducer((state, action: PayloadAction<string>) => {
      state.targetSlot = action.payload;
    }),
    unsetSelectedComponent: create.reducer((state) => {
      state.selectedComponent = undefined;
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
    selectPanning: (ui): PanningStatus => {
      return ui.panning;
    },
    selectDragging: (ui): DraggingStatus => {
      return ui.dragging;
    },
    selectSelectedComponent: (ui): string | undefined => {
      return ui.selectedComponent;
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
  setSelectedComponent,
  setHoveredComponent,
  setTargetSlot,
  unsetSelectedComponent,
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
} = uiSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectDragging,
  selectPanning,
  selectSelectedComponent,
  selectHoveredComponent,
  selectTargetSlot,
  selectCanvasViewPort,
  selectCanvasViewPortScale,
  selectLatestUndoRedoActionId,
  selectFirstLoadComplete,
  selectCanvasMode,
} = uiSlice.selectors;
