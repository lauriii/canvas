import Canvas from '@/features/canvas/Canvas';
import PrimaryPanel from '@/components/sidePanel/PrimaryPanel';
import ZoomControl from '@/components/zoom/ZoomControl';
import CodeComponentDialogs from '@/features/code-editor/dialogs/CodeComponentDialogs';
import ContextualPanel from '@/components/panel/ContextualPanel';
import { useEffect } from 'react';
import { setFirstLoadComplete } from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
import ExtensionDialog from '@/components/extensions/ExtensionDialog';
import SectionDialogs from '@/features/section/SectionDialogs';
import useLayoutWatcher from '@/hooks/useLayoutWatcher';
import useSyncParamsToState from '@/hooks/useSyncParamsToState';
import styles from './Editor.module.css';
const Editor = () => {
  const dispatch = useAppDispatch();
  useLayoutWatcher();
  useSyncParamsToState();

  useEffect(() => {
    return () => {
      dispatch(setFirstLoadComplete(false));
    };
  }, [dispatch]);

  return (
    <>
      <PrimaryPanel />
      <Canvas />
      <ContextualPanel />
      <div className={styles.absoluteContainer}>
        <ZoomControl />
        <SectionDialogs />
        <CodeComponentDialogs />
        <ExtensionDialog />
      </div>
    </>
  );
};

export default Editor;
