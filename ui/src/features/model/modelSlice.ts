import { createAppSlice } from '../../app/createAppSlice';
import type { PayloadAction } from '@reduxjs/toolkit';

type UpdateNodePayload = {
  uuid: string | undefined;
  model: {};
};

type CreateModelPayload = {
  uuid: string | undefined;
  initialData: {};
};

type setModelPayload = {
  model: {};
};

export interface ComponentModel {
  [key: string]: string | boolean | [] | number;
  name: string;
}

export interface modelSliceState {
  model: {
    [key: string]: ComponentModel;
  };
}

const initialState: modelSliceState = {
  model: {},
};

// If you are not using async thunks you can use the standalone `createSlice`.
export const modelSlice = createAppSlice({
  name: 'model',
  // `createSlice` will infer the state type from the `initialState` argument
  initialState,
  // The `reducers` field lets us define reducers and generate associated actions
  reducers: (create) => ({
    setModel: create.reducer(
      (state, action: PayloadAction<setModelPayload>) => {
        const { model } = action.payload;
        state.model = model;
      },
    ),
    updateNodeModel: create.reducer(
      (state, action: PayloadAction<UpdateNodePayload>) => {
        const { uuid, model } = action.payload;
        const randomData = { randomProp: 'random' };
        if (uuid) {
          state.model[uuid] = { ...state.model[uuid], ...model, ...randomData };
        }
      },
    ),
    createNewModel: create.reducer(
      (state, action: PayloadAction<CreateModelPayload>) => {
        const { uuid, initialData } = action.payload;
        if (uuid) {
          state.model[uuid] = { ...state.model[uuid], ...initialData };
        }
      },
    ),
  }),
  // You can define your selectors here. These selectors receive the slice
  // state as their first argument.
  selectors: {
    selectModel: (model) => model.model,
  },
});

// Action creators are generated for each case reducer function.
export const { setModel, updateNodeModel, createNewModel } = modelSlice.actions;

// Selectors returned by `slice.selectors` take the root state as their first argument.
export const { selectModel } = modelSlice.selectors;
