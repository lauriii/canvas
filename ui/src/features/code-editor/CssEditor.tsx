import CodeMirror from '@uiw/react-codemirror';
import { githubLight } from '@uiw/codemirror-theme-github';
import { useState } from 'react';
import { css } from '@codemirror/lang-css';

const CssEditor = () => {
  const [value, setValue] = useState('.test { color: red; }');

  function onChangeHandler(value: string) {
    setValue(value);
  }
  return (
    <CodeMirror
      className="xb-code-mirror-editor"
      value={value}
      onChange={onChangeHandler}
      theme={githubLight}
      extensions={[css()]}
    />
  );
};

export default CssEditor;
