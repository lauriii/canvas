import CodeMirror from '@uiw/react-codemirror';
import { javascript } from '@codemirror/lang-javascript';
import { githubLight } from '@uiw/codemirror-theme-github';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectJs, setJs } from '@/features/code-editor/codeEditorSlice';

const JavaScriptEditor = () => {
  const dispatch = useAppDispatch();
  const value = useAppSelector(selectJs);

  function onChangeHandler(value: string) {
    dispatch(setJs(value));
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
