import type React from 'react';
import styles from './PreviewOverlay.module.css';
import clsx from 'clsx';
import { useAppSelector } from '@/app/hooks';
import { selectDragging } from '@/features/ui/uiSlice';

interface PreviewOverlayProps {
  canvasPaneRef: React.RefObject<HTMLDivElement>;
  previewsContainerRef: React.RefObject<HTMLDivElement>;
}

/**
 * Renders a div at a level above where we scale the canvas into which we can portal the UI and overlay that sits over
 * the iframes. Doing this means the UI doesn't scale when the user zooms the canvas.
 */
const PreviewOverlay: React.FC<PreviewOverlayProps> = () => {
  const { isDragging } = useAppSelector(selectDragging);

  return (
    <div
      id="xbPreviewOverlay"
      className={clsx('previewOverlay', styles.overlay)}
      style={{
        pointerEvents: isDragging ? 'none' : 'all',
      }}
    ></div>
  );
};

export default PreviewOverlay;
