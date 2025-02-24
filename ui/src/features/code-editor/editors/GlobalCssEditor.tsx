import CodeMirror from '@uiw/react-codemirror';
import { githubLight } from '@uiw/codemirror-theme-github';
import { css } from '@codemirror/lang-css';
import {
  selectSourceCodeGlobalCss,
  setSourceCodeGlobalCss,
} from '@/features/code-editor/codeEditorSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';

const GlobalCssEditor = ({ isLoading }: { isLoading: boolean }) => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectSourceCodeGlobalCss);

  function onChangeHandler(value: string) {
    dispatch(setSourceCodeGlobalCss(value));
  }
  if (isLoading) {
    return null;
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
