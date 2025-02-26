import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice, createSelector } from '@reduxjs/toolkit';
import { v4 as uuidv4 } from 'uuid';
import type { RootState } from '@/app/store';
import {
  getPropMachineName,
  serializeProps,
  serializeSlots,
} from '@/features/code-editor/utils';
import type {
  CodeComponentProp,
  CodeComponentSlot,
  CodeComponentSerialized,
} from '@/types/CodeComponent';

interface CodeEditorState {
  isEditorReady: boolean;
  isGlobalCssEditorReady: boolean;
  hasCompletedFirstCompilation: boolean;
  id: string;
  status: boolean;
  name: string;
  sourceCodeCss: string;
  compiledCss: string;
  sourceCodeJs: string;
  compiledJs: string;
  sourceCodeGlobalCss: string;
  props: CodeComponentProp[];
  slots: CodeComponentSlot[];
  required: string[];
}

const initialState: CodeEditorState = {
  isEditorReady: false,
  isGlobalCssEditorReady: false,
  hasCompletedFirstCompilation: false,
  id: '',
  status: false,
  name: '',
  sourceCodeJs: '',
  compiledJs: '',
  sourceCodeCss: '',
  compiledCss: '',
  sourceCodeGlobalCss: '',
  props: [],
  slots: [],
  required: [],
};

export const codeEditorSlice = createSlice({
  name: 'codeEditor',
  initialState,
  reducers: (create) => ({
    initializeCodeEditor: create.reducer(
      (
        state,
        action: PayloadAction<
          Omit<
            CodeEditorState,
            | 'sourceCodeGlobalCss'
            | 'isEditorReady'
            | 'isGlobalCssEditorReady'
            | 'hasCompletedFirstCompilation'
            | 'compiledJs'
            | 'compiledCss'
          >
        >,
      ) => {
        state.isEditorReady = false;
        state.hasCompletedFirstCompilation = false;
        state.id = action.payload.id;
        state.status = action.payload.status;
        state.name = action.payload.name;
        state.sourceCodeJs = action.payload.sourceCodeJs;
        state.sourceCodeCss = action.payload.sourceCodeCss;
        // @todo Set sourceCodeGlobalCss
        state.props = action.payload.props;
        state.slots = action.payload.slots;
        state.required = action.payload.required;
      },
    ),
    setIsEditorReady: create.reducer(
      (state, action: PayloadAction<boolean>) => ({
        ...state,
        isEditorReady: action.payload,
      }),
    ),
    setIsGlobalCssEditorReady: create.reducer(
      (state, action: PayloadAction<boolean>) => ({
        ...state,
        isGlobalCssEditorReady: action.payload,
      }),
    ),
    setHasCompletedFirstCompilation: create.reducer(
      (state, action: PayloadAction<boolean>) => ({
        ...state,
        hasCompletedFirstCompilation: action.payload,
      }),
    ),
    resetCodeEditor: create.reducer((state) => {
      return initialState;
    }),
    setId: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      id: action.payload,
    })),
    setStatus: create.reducer((state, action: PayloadAction<boolean>) => ({
      ...state,
      status: action.payload,
    })),
    setName: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      name: action.payload,
    })),
    setSourceCodeCss: create.reducer(
      (state, action: PayloadAction<string>) => ({
        ...state,
        sourceCodeCss: action.payload,
      }),
    ),
    setCompiledCss: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      compiledCss: action.payload,
    })),
    setSourceCodeJs: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      sourceCodeJs: action.payload,
    })),
    setCompiledJs: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      compiledJs: action.payload,
    })),
    setSourceCodeGlobalCss: create.reducer(
      (state, action: PayloadAction<string>) => ({
        ...state,
        sourceCodeGlobalCss: action.payload,
      }),
    ),
    addProp: (state) => {
      state.props.push({
        id: uuidv4(),
        name: '',
        type: 'string',
        example: '',
      });
    },
    updateProp: (
      state,
      action: PayloadAction<{
        id: string;
        updates: Partial<CodeComponentProp>;
      }>,
    ) => {
      const { id, updates } = action.payload;
      const propIndex = state.props.findIndex((p) => p.id === id);
      if (propIndex !== -1) {
        const currentProp = state.props[propIndex];
        state.props[propIndex] = {
          ...currentProp,
          ...updates,
        } as CodeComponentProp;
      }
    },
    removeProp: (
      state,
      action: PayloadAction<{
        propId: string;
      }>,
    ) => {
      const { propId } = action.payload;
      const propToRemove = state.props.find((prop) => prop.id === propId);
      state.props = state.props.filter((prop) => prop.id !== propId);
      if (propToRemove) {
        state.required = state.required.filter(
          (name) => name !== getPropMachineName(propToRemove.name),
        );
      }
    },
    reorderProps: (
      state,
      action: PayloadAction<{
        oldIndex: number;
        newIndex: number;
      }>,
    ) => {
      const { oldIndex, newIndex } = action.payload;
      const props = state.props;
      const [removed] = props.splice(oldIndex, 1);
      props.splice(newIndex, 0, removed);
    },
    toggleRequired: (
      state,
      action: PayloadAction<{
        propId: string;
      }>,
    ) => {
      const { propId } = action.payload;
      const prop = state.props.find((p) => p.id === propId);
      if (!prop) return;

      const propName = getPropMachineName(prop.name);
      if (state.required.includes(propName)) {
        state.required = state.required.filter((name) => name !== propName);
      } else {
        state.required.push(propName);
      }
    },
    addSlot: (state) => {
      state.slots.push({
        id: uuidv4(),
        name: '',
        example: '',
      });
    },
    updateSlot: (
      state,
      action: PayloadAction<{
        id: string;
        updates: Partial<CodeComponentSlot>;
      }>,
    ) => {
      const { id, updates } = action.payload;
      const slotIndex = state.slots.findIndex((s) => s.id === id);
      if (slotIndex !== -1) {
        const currentSlot = state.slots[slotIndex];
        state.slots[slotIndex] = {
          ...currentSlot,
          ...updates,
        } as CodeComponentSlot;
      }
    },
    removeSlot: (
      state,
      action: PayloadAction<{
        slotId: string;
      }>,
    ) => {
      const { slotId } = action.payload;
      state.slots = state.slots.filter((slot) => slot.id !== slotId);
    },
    reorderSlots: (
      state,
      action: PayloadAction<{
        oldIndex: number;
        newIndex: number;
      }>,
    ) => {
      const { oldIndex, newIndex } = action.payload;
      const slots = state.slots;
      const [removed] = slots.splice(oldIndex, 1);
      slots.splice(newIndex, 0, removed);
    },
  }),
});

export const selectIsEditorReady = (state: RootState) =>
  state.codeEditor.isEditorReady;
export const selectIsGlobalCssEditorReady = (state: RootState) =>
  state.codeEditor.isGlobalCssEditorReady;
export const selectHasCompletedFirstCompilation = (state: RootState) =>
  state.codeEditor.hasCompletedFirstCompilation;
export const selectId = (state: RootState) => state.codeEditor.id;
export const selectStatus = (state: RootState) => state.codeEditor.status;
export const selectName = (state: RootState) => state.codeEditor.name;
export const selectSourceCodeCss = (state: RootState) =>
  state.codeEditor.sourceCodeCss;
export const selectCompiledCss = (state: RootState) =>
  state.codeEditor.compiledCss;
export const selectSourceCodeGlobalCss = (state: RootState) =>
  state.codeEditor.sourceCodeGlobalCss;
export const selectSourceCodeJs = (state: RootState) =>
  state.codeEditor.sourceCodeJs;
export const selectCompiledJs = (state: RootState) =>
  state.codeEditor.compiledJs;
export const selectProps = (state: RootState) => state.codeEditor.props;
export const selectRequired = (state: RootState) => state.codeEditor.required;
export const selectSlots = (state: RootState) => state.codeEditor.slots;

export const selectCodeComponentSerialized = createSelector(
  [
    selectId,
    selectName,
    selectStatus,
    selectProps,
    selectRequired,
    selectSlots,
    selectSourceCodeJs,
    selectSourceCodeCss,
    selectCompiledJs,
    selectCompiledCss,
  ],
  (
    id,
    name,
    status,
    props,
    required,
    slots,
    sourceCodeJs,
    sourceCodeCss,
    compiledJs,
    compiledCss,
  ): CodeComponentSerialized => ({
    machineName: id,
    name,
    status,
    props: serializeProps(props),
    required,
    slots: serializeSlots(slots),
    source_code_js: sourceCodeJs,
    source_code_css: sourceCodeCss,
    compiled_js: compiledJs,
    compiled_css: compiledCss,
  }),
);

export const {
  initializeCodeEditor,
  setIsEditorReady,
  setIsGlobalCssEditorReady,
  setHasCompletedFirstCompilation,
  resetCodeEditor,
  setStatus,
  setName,
  setSourceCodeCss,
  setCompiledCss,
  setSourceCodeJs,
  setCompiledJs,
  setSourceCodeGlobalCss,
  addProp,
  updateProp,
  removeProp,
  reorderProps,
  toggleRequired,
  addSlot,
  updateSlot,
  removeSlot,
  reorderSlots,
} = codeEditorSlice.actions;

export default codeEditorSlice;
