import CodeMirror from '@uiw/react-codemirror';
import { githubLight } from '@uiw/codemirror-theme-github';
import { css } from '@codemirror/lang-css';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectSourceCodeCss,
  setSourceCodeCss,
} from '@/features/code-editor/codeEditorSlice';

const CssEditor = ({ isLoading }: { isLoading: boolean }) => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectSourceCodeCss);

  function onChangeHandler(value: string) {
    dispatch(setSourceCodeCss(value));
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

export default CssEditor;
