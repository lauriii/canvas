import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

export interface PrimaryPanelState {
  activePanel: string;
  isHidden: boolean;
  openLayoutItems: string[];
  clickToInsertState: ClickToInsertState;
}

interface ClickToInsertState {
  isEnabled: boolean;
  // The uuid of the element that the user clicked on to insert a new node.
  originUUID: string | undefined;
  originLayoutItemType: LayoutItemType;
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
  clickToInsertState: {
    isEnabled: false,
    originUUID: undefined,
    originLayoutItemType: LayoutItemType.UNDEFINED,
  },
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
    enableClickToInsert: create.reducer(
      (state, action: PayloadAction<ClickToInsertState>) => {
        state.clickToInsertState.isEnabled = action.payload.isEnabled;
        state.clickToInsertState.originUUID = action.payload.originUUID;
        state.clickToInsertState.originLayoutItemType =
          action.payload.originLayoutItemType;
      },
    ),
    disableClickToInsert: create.reducer((state) => {
      state.clickToInsertState.isEnabled = false;
      state.clickToInsertState.originUUID = undefined;
    }),
  }),
  selectors: {
    selectActivePanel: (primaryPanel): string => {
      return primaryPanel.activePanel;
    },
    selectOpenLayoutItems: (primaryPanel): string[] => {
      return primaryPanel.openLayoutItems;
    },
    selectClickToInsertState: (primaryPanel): ClickToInsertState => {
      return primaryPanel.clickToInsertState;
    },
  },
});

// Action creators are generated for each case reducer function.
export const {
  setActivePanel,
  setOpenLayoutItem,
  enableClickToInsert,
  disableClickToInsert,
  setCloseLayoutItem,
} = primaryPanelSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectActivePanel,
  selectOpenLayoutItems,
  selectClickToInsertState,
} = primaryPanelSlice.selectors;
