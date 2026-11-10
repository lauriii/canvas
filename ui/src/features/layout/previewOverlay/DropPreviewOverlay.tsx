import clsx from 'clsx';

import { useAppSelector } from '@/app/hooks';
import { DROP_PREVIEW_ID } from '@/features/layout/preview/livePreview';
import { usePreviewGeometry } from '@/features/layout/preview/PreviewGeometryContext';
import { selectEditorViewPortScale } from '@/features/ui/uiSlice';

import type React from 'react';

import styles from './PreviewOverlay.module.css';

/**
 * Outlines a component from the library shown where it will be dropped.
 */
const DropPreviewOverlay: React.FC = () => {
  const { geometryMap } = usePreviewGeometry();
  const scale = useAppSelector(selectEditorViewPortScale);
  const rect = geometryMap.component[DROP_PREVIEW_ID]?.rect;
  if (!rect) {
    return null;
  }
  return (
    <div
      className={clsx(styles.componentOverlay, styles.dragging)}
      data-canvas-drop-preview
      style={{
        top: rect.top * scale,
        left: rect.left * scale,
        width: rect.width * scale,
        height: rect.height * scale,
        pointerEvents: 'none',
      }}
    />
  );
};

export default DropPreviewOverlay;
