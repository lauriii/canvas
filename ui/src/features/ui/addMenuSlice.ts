import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

export interface AddMenuState {
  activeMenu: string;
  isHidden: boolean;
  activeSecondLevelMenu: string;
  clickToInsertState: ClickToInsertState;
}

interface ClickToInsertState {
  isEnabled: boolean;
  // The uuid of the element that the user clicked on to insert a new node.
  originUUID: string | undefined;
  originNodeType: NodeType;
}

export enum NodeType {
  SECTION = 'section',
  COMPONENT = 'component',
  UNDEFINED = 'undefined',
}

const initialState: AddMenuState = {
  activeMenu: '',
  isHidden: false,
  activeSecondLevelMenu: '',
  clickToInsertState: {
    isEnabled: false,
    originUUID: undefined,
    originNodeType: NodeType.UNDEFINED,
  },
};

export const ADD_MENU_ITEMS = {
  ADD_ID: 'add',
  // Second level items
  DEFAULT_COMPONENTS_ID: 'default',
  CUSTOM_COMPONENTS_ID: 'custom',
  SECTION_ID: 'section',
};

export const addMenuSlice = createAppSlice({
  name: 'addMenu',
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: (create) => ({
    setActiveMenu: create.reducer((state, action: PayloadAction<string>) => {
      state.activeMenu = action.payload;
    }),
    setInactive: create.reducer((state) => {
      state.activeMenu = '';
      // When the menu is set to an empty string, it's closed. So, we should reset the
      // hidden state back to false here too to reset the css display property.
      state.isHidden = false;
    }),
    setHidden: create.reducer((state, action: PayloadAction<boolean>) => {
      state.isHidden = action.payload;
    }),
    setActiveSecondLevelMenu: create.reducer(
      (state, action: PayloadAction<string>) => {
        state.activeMenu = ADD_MENU_ITEMS.ADD_ID;
        state.activeSecondLevelMenu = action.payload;
      },
    ),
    enableClickToInsert: create.reducer(
      (state, action: PayloadAction<ClickToInsertState>) => {
        state.clickToInsertState.isEnabled = action.payload.isEnabled;
        state.clickToInsertState.originUUID = action.payload.originUUID;
        state.clickToInsertState.originNodeType = action.payload.originNodeType;
      },
    ),
    disableClickToInsert: create.reducer((state) => {
      state.clickToInsertState.isEnabled = false;
      state.clickToInsertState.originUUID = undefined;
    }),
  }),
  selectors: {
    selectActiveMenu: (addMenu): string => {
      return addMenu.activeMenu;
    },
    selectIsHidden: (addMenu): boolean => {
      return addMenu.isHidden;
    },
    selectActiveSecondLevelMenu: (addMenu): string => {
      return addMenu.activeSecondLevelMenu;
    },
    selectClickToInsertState: (addMenu): ClickToInsertState => {
      return addMenu.clickToInsertState;
    },
  },
});

// Action creators are generated for each case reducer function.
export const {
  setActiveMenu,
  setInactive,
  setHidden,
  setActiveSecondLevelMenu,
  enableClickToInsert,
  disableClickToInsert,
} = addMenuSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectActiveMenu,
  selectIsHidden,
  selectActiveSecondLevelMenu,
  selectClickToInsertState,
} = addMenuSlice.selectors;
