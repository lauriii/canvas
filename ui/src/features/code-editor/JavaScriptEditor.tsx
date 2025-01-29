import CodeMirror from '@uiw/react-codemirror';
import { javascript } from '@codemirror/lang-javascript';
import { githubLight } from '@uiw/codemirror-theme-github';
import { useState } from 'react';

const JavaScriptEditor = () => {
  const exampleString = `const Example = () => {
  return (
    <div>
      <h1>Hello, World!</h1>
      <p>This is a JSX component.</p>
    </div>
  );
};

export default MyComponent;
`;
  const [value, setValue] = useState(exampleString);

  function onChangeHandler(value: string) {
    setValue(value);
  }
  return (
    <CodeMirror
      className="xb-code-mirror-editor"
      value={value}
      onChange={onChangeHandler}
      theme={githubLight}
      extensions={[javascript({ jsx: true })]}
    />
  );
};

export default JavaScriptEditor;
