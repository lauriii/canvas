import { createAppSlice } from "../../app/createAppSlice";
import type { PayloadAction } from "@reduxjs/toolkit";


type UpdateNodePayload = {
  uuid: string | undefined;
  model: {}
};

export interface ComponentModel {
  [key: string]: string | boolean | [] | number;
  name: string;
}

export interface modelSliceState {
  model: {
    [key: string]: ComponentModel
  };
}

const initialState: modelSliceState = {
  model: {
    "1" : {
      name: 'Component 1 (no slots)',
      foo: 'aaa',
    },
    "2" : {
      name: 'Component 2 (1 slots)'
    },
    "3" : {
      name: 'Component 3 (2 slots)'
    },
    "4" : {
      name: 'Component 4 (no slots)'
    },
    "5" : {
      name: 'Component 5 (no slots)'
    },
  }
};

// If you are not using async thunks you can use the standalone `createSlice`.
export const modelSlice = createAppSlice({
  name: "model",
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: create => ({
    updateNodeModel: create.reducer((state, action: PayloadAction<UpdateNodePayload>) => {
      const {uuid, model} = action.payload;
      const randomData = {randomProp: 'random'}
      if(uuid) {
        state.model[uuid] = {...state.model[uuid], ...model, ...randomData};
      }
    }),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectModel: model => model.model,
  },
});

// Action creators are generated for each case reducer function.
export const { updateNodeModel } = modelSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectModel } = modelSlice.selectors;
