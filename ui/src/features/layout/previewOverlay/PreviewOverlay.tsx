import type React from 'react';
import styles from './PreviewOverlay.module.css';
import clsx from 'clsx';

/**
 * Renders a div at a level above where we scale the canvas into which we can portal the UI and overlay that sits over
 * the iframes. Doing this means the UI doesn't scale when the user zooms the canvas.
 */
const PreviewOverlay: React.FC = () => {
  return (
    <div
      id="xbPreviewOverlay"
      className={clsx('previewOverlay', styles.overlay)}
    ></div>
  );
};

export default PreviewOverlay;
