import { css } from '@codemirror/lang-css';
import { githubLight } from '@uiw/codemirror-theme-github';
import CodeMirror from '@uiw/react-codemirror';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';

import styles from '@/features/code-editor/CanvasCodeMirror.module.css';

const CssEditor = ({ isLoading }: { isLoading: boolean }) => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectCodeComponentProperty('sourceCodeCss'));

  function onChangeHandler(value: string) {
    dispatch(setCodeComponentProperty(['sourceCodeCss', value]));
  }
  if (isLoading) {
    return null;
  }
  return (
    <CodeMirror
      className={styles.canvasCodeMirrorEditor}
      value={value}
      onChange={onChangeHandler}
      theme={githubLight}
      extensions={[css()]}
      width="100%"
      height="100%"
    />
  );
};

export default CssEditor;
