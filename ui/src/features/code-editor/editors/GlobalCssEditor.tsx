import CodeMirror from '@uiw/react-codemirror';
import { githubLight } from '@uiw/codemirror-theme-github';
import { css } from '@codemirror/lang-css';
import {
  selectGlobalCss,
  setGlobalCss,
} from '@/features/code-editor/codeEditorSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';

const GlobalCssEditor = () => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectGlobalCss);

  function onChangeHandler(value: string) {
    dispatch(setGlobalCss(value));
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

export default GlobalCssEditor;
