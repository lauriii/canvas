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
  isPanningIFrame: boolean;
  isPanningParent: boolean;
}

export interface CanvasViewPort {
  x: number;
  y: number;
  scale: number;
}

export interface uiSliceState {
  pending: boolean;
  dragging: DraggingStatus;
  panning: PanningStatus;
  selectedComponent: string | undefined; //uuid of component
  hoveredComponent: string | undefined; //uuid of component
  contextualPanelOpen: boolean;
  canvasViewport: CanvasViewPort;
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
    isPanningIFrame: false,
    isPanningParent: false,
  },
  selectedComponent: undefined,
  hoveredComponent: undefined,
  contextualPanelOpen: false,
  canvasViewport: {
    x: 0,
    y: 0,
    scale: 1,
  },
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

// const scaleValues = [
//   0.25, 0.33, 0.5, 0.67, 0.75, 0.8, 0.9, 1, 1.1, 1.25, 1.5, 1.75, 2, 2.5, 3, 4,
//   5,
// ];

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
    setPanningIFrame: create.reducer(
      (state, action: PayloadAction<boolean>) => {
        state.panning.isPanning = action.payload;
        state.panning.isPanningIFrame = action.payload;
      },
    ),
    setPanningParent: create.reducer(
      (state, action: PayloadAction<boolean>) => {
        state.panning.isPanning = action.payload;
        state.panning.isPanningParent = action.payload;
      },
    ),
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
    unsetSelectedComponent: create.reducer((state) => {
      state.selectedComponent = undefined;
    }),
    unsetHoveredComponent: create.reducer((state) => {
      state.hoveredComponent = undefined;
    }),
    setContextualPanelOpen: create.reducer(
      (state, action: PayloadAction<boolean>) => {
        state.contextualPanelOpen = action.payload;
      },
    ),
    setCanvasViewPort: create.reducer(
      (state, action: PayloadAction<UpdateViewportPayload>) => {
        state.canvasViewport.x = action.payload.x || state.canvasViewport.x;
        state.canvasViewport.y = action.payload.y || state.canvasViewport.y;
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
      const currentIndex = scaleValues.findIndex(
        (value) => value.scale === state.canvasViewport.scale,
      );
      const nextIndex =
        currentIndex + 1 < scaleValues.length ? currentIndex + 1 : currentIndex;
      state.canvasViewport.scale = scaleValues[nextIndex].scale;
    }),
    canvasViewPortZoomOut: create.reducer((state, action) => {
      const currentIndex = scaleValues.findIndex(
        (value) => value.scale === state.canvasViewport.scale,
      );
      const prevIndex = currentIndex - 1 >= 0 ? currentIndex - 1 : currentIndex;
      state.canvasViewport.scale = scaleValues[prevIndex].scale;
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
    selectContextualPanelOpen: (ui): boolean => {
      return ui.contextualPanelOpen;
    },
    selectCanvasViewPort: (ui): CanvasViewPort => {
      return ui.canvasViewport;
    },
  },
});

// Action creators are generated for each case reducer function.
export const {
  setPending,
  setTreeDragging,
  setPreviewDragging,
  setListDragging,
  setPanningIFrame,
  setPanningParent,
  setSelectedComponent,
  setHoveredComponent,
  unsetSelectedComponent,
  unsetHoveredComponent,
  setContextualPanelOpen,
  setCanvasViewPort,
  canvasViewPortZoomIn,
  canvasViewPortZoomOut,
  canvasViewPortZoomDelta,
} = uiSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectDragging,
  selectPanning,
  selectSelectedComponent,
  selectHoveredComponent,
  selectContextualPanelOpen,
  selectCanvasViewPort,
} = uiSlice.selectors;
