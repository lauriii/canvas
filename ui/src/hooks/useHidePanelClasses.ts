import { useMemo } from 'react';
import styles from '@/components/Panel.module.css';
import { useAppSelector } from '@/app/hooks';
import { selectCanvasMode } from '@/features/ui/uiSlice';

function useHidePanelClasses(side: 'left' | 'right'): string[] {
  const canvasMode = useAppSelector(selectCanvasMode);

  return useMemo(() => {
    if (side === 'left' && canvasMode === 'interactive') {
      return [styles.offLeft, styles.animateOff];
    }
    if (side === 'right' && canvasMode === 'interactive') {
      return [styles.offRight, styles.animateOff];
    }
    return [styles.animateOff];
  }, [canvasMode, side]);
}

export default useHidePanelClasses;
