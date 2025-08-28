import { useMemo } from 'react';
import styles from '@/components/Panel.module.css';
import { useAppSelector } from '@/app/hooks';
import { selectEditorFrameMode } from '@/features/ui/uiSlice';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';

function useHidePanelClasses(side: 'left' | 'right'): string[] {
  const editorFrameMode = useAppSelector(selectEditorFrameMode);
  const activePanel = useAppSelector(selectActivePanel);

  return useMemo(() => {
    if (
      side === 'left' &&
      (editorFrameMode === 'interactive' || !activePanel)
    ) {
      return [styles.offLeft, styles.animateOff];
    }
    if (side === 'right' && editorFrameMode === 'interactive') {
      return [styles.offRight, styles.animateOff];
    }
    return [styles.animateOff];
  }, [activePanel, editorFrameMode, side]);
}

export default useHidePanelClasses;
