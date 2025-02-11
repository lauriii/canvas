import type { PayloadAction } from '@reduxjs/toolkit';
import { createSlice } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

interface CodeEditorState {
  css: string;
  js: string;
  globalCss: string;
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
  }),
});

export const selectCss = (state: RootState) => state.codeEditor.css;
export const selectGlobalCss = (state: RootState) => state.codeEditor.globalCss;
export const selectJs = (state: RootState) => state.codeEditor.js;

export const { setCss, setJs, setGlobalCss } = codeEditorSlice.actions;

export default codeEditorSlice;
