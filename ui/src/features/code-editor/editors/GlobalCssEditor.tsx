import { css } from '@codemirror/lang-css';
import { githubLight } from '@uiw/codemirror-theme-github';
import CodeMirror from '@uiw/react-codemirror';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectGlobalAssetLibraryProperty,
  setGlobalAssetLibraryProperty,
} from '@/features/code-editor/codeEditorSlice';

import styles from '../CanvasCodeMirror.module.css';

const GlobalCssEditor = ({ isLoading }: { isLoading: boolean }) => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(
    selectGlobalAssetLibraryProperty(['css', 'original']),
  );

  function onChangeHandler(value: string) {
    dispatch(setGlobalAssetLibraryProperty(['css', 'original', value]));
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

export default GlobalCssEditor;
