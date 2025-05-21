import Canvas from '@/features/canvas/Canvas';
import PrimaryPanel from '@/components/sidePanel/PrimaryPanel';
import CodeComponentDialogs from '@/features/code-editor/dialogs/CodeComponentDialogs';
import ContextualPanel from '@/components/panel/ContextualPanel';
import Layout from '@/features/layout/Layout';
import { useEffect } from 'react';
import { setFirstLoadComplete } from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
import ExtensionDialog from '@/components/extensions/ExtensionDialog';
import SectionDialogs from '@/features/section/SectionDialogs';
import useLayoutWatcher from '@/hooks/useLayoutWatcher';
import useSyncParamsToState from '@/hooks/useSyncParamsToState';
import styles from './Editor.module.css';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import { useUndoRedo } from '@/hooks/useUndoRedo';
const Editor = () => {
  const dispatch = useAppDispatch();
  useLayoutWatcher();
  useSyncParamsToState();
  const { isUndoable, dispatchUndo } = useUndoRedo();

  useEffect(() => {
    return () => {
      dispatch(setFirstLoadComplete(false));
    };
  }, [dispatch]);

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
        <SectionDialogs />
        <CodeComponentDialogs />
        <ExtensionDialog />
      </div>
    </>
  );
};

export default Editor;
