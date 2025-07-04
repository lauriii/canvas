import Canvas from '@/features/canvas/Canvas';
import PrimaryPanel from '@/components/sidePanel/PrimaryPanel';
import CodeComponentDialogs from '@/features/code-editor/dialogs/CodeComponentDialogs';
import ContextualPanel from '@/components/panel/ContextualPanel';
import Layout from '@/features/layout/Layout';
import { useEffect } from 'react';
import { setFirstLoadComplete } from '@/features/ui/uiSlice';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ExtensionDialog from '@/components/extensions/ExtensionDialog';
import PatternDialogs from '@/features/pattern/PatternDialogs';
import useLayoutWatcher from '@/hooks/useLayoutWatcher';
import useSyncParamsToState from '@/hooks/useSyncParamsToState';
import styles from './Editor.module.css';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import { useUndoRedo } from '@/hooks/useUndoRedo';
import { selectLatestError } from '@/features/error-handling/queryErrorSlice';
import ConflictWarning from '@/features/editor/ConflictWarning';
const Editor = () => {
  const dispatch = useAppDispatch();
  useLayoutWatcher();
  useSyncParamsToState();
  const { isUndoable, dispatchUndo } = useUndoRedo();
  const latestError = useAppSelector(selectLatestError);

  useEffect(() => {
    return () => {
      dispatch(setFirstLoadComplete(false));
    };
  }, [dispatch]);

  if (latestError) {
    if (latestError.status === '409') {
      // There has been an editing conflict and the user should be blocked from continuing!
      return <ConflictWarning />;
    }
  }

  return (
    <>
      <PrimaryPanel />
      <ErrorBoundary
        title="An unexpected error has occurred while fetching the layout."
        variant="alert"
        onReset={isUndoable ? dispatchUndo : undefined}
        resetButtonText={isUndoable ? 'Undo last action' : undefined}
      >
        <Layout />
      </ErrorBoundary>
      <Canvas />
      <ContextualPanel />
      <div className={styles.absoluteContainer}>
        <PatternDialogs />
        <CodeComponentDialogs />
        <ExtensionDialog />
      </div>
    </>
  );
};

export default Editor;
