import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

export interface PrimaryPanelState {
  activePanel: string;
  isHidden: boolean;
  openLayoutItems: string[];
  uniqueListId: string;
}

export enum LayoutItemType {
  SECTION = 'section',
  COMPONENT = 'component',
  CODE = 'code',
  OVERRIDE = 'override',
  UNDEFINED = 'undefined',
}

const initialState: PrimaryPanelState = {
  activePanel: 'layers',
  isHidden: false,
  // Open the component dropdown by default.
  openLayoutItems: [LayoutItemType.COMPONENT],
  uniqueListId: '',
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
    setUniqueListId: create.reducer((state, action: PayloadAction<string>) => {
      state.uniqueListId = action.payload;
    }),
  }),
  selectors: {
    selectActivePanel: (primaryPanel): string => {
      return primaryPanel.activePanel;
    },
    selectOpenLayoutItems: (primaryPanel): string[] => {
      return primaryPanel.openLayoutItems;
    },
    selectUniqueListId: (primaryPanel): string => {
      return primaryPanel.uniqueListId;
    },
  },
});

// Action creators are generated for each case reducer function.
export const {
  setActivePanel,
  setOpenLayoutItem,
  setCloseLayoutItem,
  setUniqueListId,
} = primaryPanelSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectActivePanel, selectOpenLayoutItems, selectUniqueListId } =
  primaryPanelSlice.selectors;
