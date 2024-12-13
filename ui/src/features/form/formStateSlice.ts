import {
  createSelector,
  createSlice,
  type PayloadAction,
} from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';
import type { InputMessage } from '@/types/Form';

export interface FormState {
  values: Record<string, any>;
  errors: Record<string, InputMessage>;
}

export interface FormStateSliceState {
  component_props_form: FormState;
  page_data_form: FormState;
}

const emptyFormState = {
  values: {},
  errors: {},
};

const initialState: FormStateSliceState = {
  component_props_form: emptyFormState,
  page_data_form: emptyFormState,
};

export type FormId = keyof FormStateSliceState;

type SetFieldErrorPayload = {
  formId: FormId;
  fieldName: string;
  type: 'error' | 'warning' | 'info';
  message: string;
};

type ClearFieldErrorPayload = {
  formId: FormId;
  fieldName: string;
};

type SetFieldValuePayload = {
  formId: FormId;
  fieldName: string;
  value: any;
};

export const formStateSlice = createSlice({
  name: 'formState',
  initialState,
  reducers: (create) => ({
    clearFieldValues: create.reducer(
      (state, action: PayloadAction<FormId>) => ({
        ...state,
        [action.payload]: { errors: {}, values: {} },
      }),
    ),
    setFieldError: create.reducer(
      (state, action: PayloadAction<SetFieldErrorPayload>) => ({
        ...state,
        [action.payload.formId]: {
          ...state[action.payload.formId],
          errors: {
            ...state[action.payload.formId].errors,
            [action.payload.fieldName]: {
              message: action.payload.message,
              type: action.payload.type,
            },
          },
        },
      }),
    ),
    clearFieldError: create.reducer(
      (state, action: PayloadAction<ClearFieldErrorPayload>) => {
        delete state[action.payload.formId].errors[action.payload.fieldName];
        return { ...state };
      },
    ),
    setFieldValue: create.reducer(
      (state, action: PayloadAction<SetFieldValuePayload>) => ({
        ...state,
        [action.payload.formId]: {
          ...state[action.payload.formId],
          values: {
            ...state[action.payload.formId].values,
            [action.payload.fieldName]: action.payload.value,
          },
        },
      }),
    ),
  }),
});

export interface FieldIdentifier {
  formId: FormId;
  fieldName: string;
}

const selectFormStateForForm = (state: RootState, formId: FormId) => formId;
const selectFormState = (state: RootState) => state.formState;
const selectFieldIdentifiers = (
  state: RootState,
  fieldIdentifiers: FieldIdentifier,
) => fieldIdentifiers;

export const selectFormValues = createSelector(
  [selectFormState, selectFormStateForForm],
  (formState: FormStateSliceState, formId: FormId) =>
    formState[formId]?.values || {},
);

export const selectFieldValue = createSelector(
  [selectFormState, selectFieldIdentifiers],
  (formState: FormStateSliceState, identifiers: FieldIdentifier) =>
    formState[identifiers.formId]?.values[identifiers.fieldName] || null,
);

export const selectFieldError = createSelector(
  [selectFormState, selectFieldIdentifiers],
  (formState: FormStateSliceState, identifiers: FieldIdentifier) =>
    formState[identifiers.formId]?.errors[identifiers.fieldName] || null,
);

export const {
  setFieldError,
  setFieldValue,
  clearFieldError,
  clearFieldValues,
} = formStateSlice.actions;
