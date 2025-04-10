import Canvas from '@/features/canvas/Canvas';
import PrimaryPanel from '@/components/sidebar/PrimaryPanel';
import ZoomControl from '@/components/zoom/ZoomControl';
import CodeComponentDialogs from '@/features/code-editor/dialogs/CodeComponentDialogs';
import ContextualPanel from '@/components/panel/ContextualPanel';
import { useEffect } from 'react';
import { setFirstLoadComplete } from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
import ExtensionDialog from '@/components/extensions/ExtensionDialog';
import SectionDialogs from '@/features/section/SectionDialogs';
import useLayoutWatcher from '@/hooks/useLayoutWatcher';
const Editor = () => {
  const dispatch = useAppDispatch();
  useLayoutWatcher();

  useEffect(() => {
    return () => {
      dispatch(setFirstLoadComplete(false));
    };
  }, [dispatch]);

  return (
    <>
      <Canvas />
      <PrimaryPanel />
      <ContextualPanel />
      <ZoomControl />
      <SectionDialogs />
      <CodeComponentDialogs />
      <ExtensionDialog />
    </>
  );
};

export default Editor;
