import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import { v4 as uuidv4 } from 'uuid';
import type { RootState } from '@/app/store';
import type {
  CodeComponentProp,
  CodeComponentSlot,
} from '@/types/CodeComponent';

interface CodeEditorState {
  css: string;
  js: string;
  globalCss: string;
  props: CodeComponentProp[];
  slots: CodeComponentSlot[];
  required: string[];
}

const exampleJs = `import { useState } from 'preact/hooks';

export default function Counter() {
  const [count, setCount] = useState(0);

  const add = () => setCount(count + 1);
  const subtract = () => setCount(count - 1);

  return (
    <div>
      <div className="rounded-5xl bg-primary shadow-lg p-6 max-w-sm text-center">
        <h1 className="test-class text-3xl font-bold mb-4">
          Counter
        </h1>
        <p className="px-4 text-center">Contented get distrusts certainty nay are frankness concealed ham. On unaffected resolution on considered of. No thought me husband or colonel forming effects.</p>
        <div className="flex items-center justify-center space-x-4 py-4">
          <button
            className="bg-secondary hover:bg-secondary/80 text-white font-bold py-2 px-4 rounded-full transition duration-150 ease-in-out focus:outline-none shadow"
            onClick={subtract}
          >
            -
          </button>
          <pre className="text-4xl font-semibold">{count}</pre>
          <button
            className="bg-secondary hover:bg-secondary/80 text-white font-bold py-2 px-4 rounded-full transition duration-150 ease-in-out focus:outline-none shadow"
            onClick={add}
          >
            +
          </button>
        </div>
      </div>
    </div>
  );
};
`;

const exampleCss =
  '.test-class {\n' +
  '  color: #151515;\n' +
  '  text-decoration: underline;\n' +
  '}';

const exampleGlobalCss =
  '@theme {\n' +
  '  /* Colors */\n' +
  '  --color-primary: #a9e5bb;\n' +
  '  --color-secondary: #3772FF;\n' +
  '\n' +
  '  --radius-5xl: 3rem;\n' +
  '  --spacing-4: 1rem;\n' +
  ' }';

const initialState: CodeEditorState = {
  js: exampleJs,
  css: exampleCss,
  globalCss: exampleGlobalCss,
  props: [],
  slots: [],
  required: [],
};

export const codeEditorSlice = createSlice({
  name: 'codeEditor',
  initialState,
  reducers: (create) => ({
    setCss: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      css: action.payload,
    })),
    setJs: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      js: action.payload,
    })),
    setGlobalCss: create.reducer((state, action: PayloadAction<string>) => ({
      ...state,
      globalCss: action.payload,
    })),
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
      state.props = state.props.filter((prop) => prop.id !== propId);
      state.required = state.required.filter((value) => value !== propId);
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
      if (state.required.includes(propId)) {
        state.required = state.required.filter((id) => id !== propId);
      } else {
        state.required.push(propId);
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

export const selectCss = (state: RootState) => state.codeEditor.css;
export const selectGlobalCss = (state: RootState) => state.codeEditor.globalCss;
export const selectJs = (state: RootState) => state.codeEditor.js;
export const selectProps = (state: RootState) => state.codeEditor.props;
export const selectRequired = (state: RootState) => state.codeEditor.required;
export const selectSlots = (state: RootState) => state.codeEditor.slots;

export const {
  setCss,
  setJs,
  setGlobalCss,
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
