import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

export interface PrimaryPanelState {
  activePanel: string;
  isHidden: boolean;
  openLayoutItems: string[];
}

export enum LayoutItemType {
  SECTION = 'section',
  COMPONENT = 'component',
  UNDEFINED = 'undefined',
}

const initialState: PrimaryPanelState = {
  activePanel: 'layers',
  isHidden: false,
  // Open the component dropdown by default.
  openLayoutItems: [LayoutItemType.COMPONENT],
};

export const primaryPanelSlice = createAppSlice({
  name: 'primaryPanel',
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: (create) => ({
    setActivePanel: create.reducer((state, action: PayloadAction<string>) => {
      state.activePanel = action.payload;
    }),
    setOpenLayoutItem: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.openLayoutItems = [...state.openLayoutItems, action.payload];
      },
    ),
    setCloseLayoutItem: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.openLayoutItems = state.openLayoutItems.filter(
          (item) => item !== action.payload,
        );
      },
    ),
  }),
  selectors: {
    selectActivePanel: (primaryPanel): string => {
      return primaryPanel.activePanel;
    },
    selectOpenLayoutItems: (primaryPanel): string[] => {
      return primaryPanel.openLayoutItems;
    },
  },
});

// Action creators are generated for each case reducer function.
export const { setActivePanel, setOpenLayoutItem, setCloseLayoutItem } =
  primaryPanelSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectActivePanel, selectOpenLayoutItems } =
  primaryPanelSlice.selectors;
