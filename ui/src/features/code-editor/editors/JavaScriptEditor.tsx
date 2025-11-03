import { javascript } from '@codemirror/lang-javascript';
import { githubLight } from '@uiw/codemirror-theme-github';
import CodeMirror from '@uiw/react-codemirror';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import ImportButton from '@/features/code-editor/ImportButton';

import styles from '@/features/code-editor/CanvasCodeMirror.module.css';

const JavaScriptEditor = ({ isLoading }: { isLoading: boolean }) => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectCodeComponentProperty('sourceCodeJs'));

  function onChangeHandler(value: string) {
    dispatch(setCodeComponentProperty(['sourceCodeJs', value]));
  }
  if (isLoading) {
    return null;
  }
  return (
    <>
      <CodeMirror
        className={styles.canvasCodeMirrorEditor}
        value={value}
        onChange={onChangeHandler}
        theme={githubLight}
        extensions={[javascript({ jsx: true })]}
        width="100%"
        height="100%"
      />
      <ImportButton />
    </>
  );
};

export default JavaScriptEditor;
