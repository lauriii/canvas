import { createAppSlice } from '@/app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';
import { PRIMARY_MENU_ITEMS } from '@/components/sidebar/primary/PrimaryMenubar';

export interface PrimaryMenuState {
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

const initialState: PrimaryMenuState = {
  activeMenu: '',
  isHidden: false,
  activeSecondLevelMenu: '',
  clickToInsertState: {
    isEnabled: false,
    originUUID: undefined,
    originNodeType: NodeType.UNDEFINED,
  },
};

export const primaryMenuSlice = createAppSlice({
  name: 'primaryMenu',
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
        state.activeMenu = PRIMARY_MENU_ITEMS.ADD_ELEMENT_ID;
        state.activeSecondLevelMenu = action.payload;
      },
    ),
    setInactiveSecondLevelMenu: create.reducer((state) => {
      state.activeSecondLevelMenu = '';
    }),
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
    selectActiveMenu: (primaryMenu): string => {
      return primaryMenu.activeMenu;
    },
    selectIsHidden: (primaryMenu): boolean => {
      return primaryMenu.isHidden;
    },
    selectActiveSecondLevelMenu: (primaryMenu): string => {
      return primaryMenu.activeSecondLevelMenu;
    },
    selectClickToInsertState: (primaryMenu): ClickToInsertState => {
      return primaryMenu.clickToInsertState;
    },
  },
});

// Action creators are generated for each case reducer function.
export const {
  setActiveMenu,
  setInactive,
  setHidden,
  setActiveSecondLevelMenu,
  setInactiveSecondLevelMenu,
  enableClickToInsert,
  disableClickToInsert,
} = primaryMenuSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const {
  selectActiveMenu,
  selectIsHidden,
  selectActiveSecondLevelMenu,
  selectClickToInsertState,
} = primaryMenuSlice.selectors;
