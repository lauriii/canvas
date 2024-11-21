import Canvas from '@/features/canvas/Canvas';
import PrimaryPanel from '@/components/sidebar/PrimaryPanel';
import ZoomControl from '@/components/zoom/ZoomControl';
import SaveSectionDialog from '@/features/saveSection/SaveSectionDialog';
import ContextualPanel from '@/components/panel/ContextualPanel';
import { useEffect } from 'react';
import { setFirstLoadComplete } from '@/features/ui/uiSlice';
import { useAppDispatch } from '@/app/hooks';
const Editor = () => {
  const dispatch = useAppDispatch();

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
      <div id="menuBarContainer"></div>
      <div id="menuBarSubmenuContainer"></div>
      <SaveSectionDialog />
    </>
  );
};

export default Editor;
