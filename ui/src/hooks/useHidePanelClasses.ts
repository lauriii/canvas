import { useMemo } from 'react';
import styles from '@/components/Panel.module.css';
import { useAppSelector } from '@/app/hooks';
import { selectCanvasMode } from '@/features/ui/uiSlice';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';

function useHidePanelClasses(side: 'left' | 'right'): string[] {
  const canvasMode = useAppSelector(selectCanvasMode);
  const activePanel = useAppSelector(selectActivePanel);

  return useMemo(() => {
    if (side === 'left' && (canvasMode === 'interactive' || !activePanel)) {
      return [styles.offLeft, styles.animateOff];
    }
    if (side === 'right' && canvasMode === 'interactive') {
      return [styles.offRight, styles.animateOff];
    }
    return [styles.animateOff];
  }, [activePanel, canvasMode, side]);
}

export default useHidePanelClasses;
