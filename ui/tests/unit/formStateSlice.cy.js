import {
  formStateSlice,
  initialState,
  setFieldError,
  setFieldValue,
  clearFieldValues,
  clearFieldError,
  setCurrentComponent,
} from '@/features/form/formStateSlice';

import { makeStore } from '@/app/store';

const formId = 'component_inputs_form';
const fieldName = 'b741';

describe('Form state slice 🔪', () => {
  it('Should set field value', () => {
    const state = formStateSlice.reducer(
      initialState,
      setFieldValue({
        formId,
        fieldName,
        value: "Okay, let's ride",
      }),
    );
    expect(state.component_inputs_form.values).to.deep.eq({
      b741: "Okay, let's ride",
    });
  });

  it('Should set field error', () => {
    const state = formStateSlice.reducer(
      initialState,
      setFieldError({
        formId,
        fieldName,
        type: 'error',
        message: 'Its tempo paints my world in gray',
      }),
    );
    expect(state.component_inputs_form.errors).to.deep.eq({
      b741: { type: 'error', message: 'Its tempo paints my world in gray' },
    });
  });

  it('Should clear field error', () => {
    const state = formStateSlice.reducer(
      {
        ...initialState,
        [formId]: {
          errors: {
            b741: {
              type: 'error',
              message: 'Its tempo paints my world in gray',
            },
          },
          values: {},
        },
      },
      clearFieldError({ formId, fieldName }),
    );
    expect(state.component_inputs_form.errors).to.deep.eq({});
  });

  it('Should clear values', () => {
    const state = formStateSlice.reducer(
      {
        ...initialState,
        [formId]: {
          errors: {},
          values: {
            b741: "Okay, let's ride",
          },
        },
      },
      clearFieldValues(formId),
    );
    expect(state.component_inputs_form.values).to.deep.eq({});
  });

  it('Should clear form state when component changes', () => {
    const store = makeStore({
      formState: {
        ...initialState,
        [formId]: {
          errors: {},
          values: {
            b741: "Okay, let's ride",
          },
        },
      },
    });
    expect(store.getState().formState.component_inputs_form.values).to.deep.eq({
      b741: "Okay, let's ride",
    });
    store.dispatch(setCurrentComponent('clench-the-moment'));
    expect(store.getState().formState.component_inputs_form.values).to.deep.eq(
      {},
    );
  });
});
