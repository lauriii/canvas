import CodeMirror from '@uiw/react-codemirror';
import { githubLight } from '@uiw/codemirror-theme-github';
import { css } from '@codemirror/lang-css';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectCss, setCss } from '@/features/code-editor/codeEditorSlice';

const CssEditor = () => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectCss);

  function onChangeHandler(value: string) {
    dispatch(setCss(value));
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
