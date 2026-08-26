import { useRef } from 'react';
import { useParams } from 'react-router';

import {
  calculateComponentPreviewSizing,
  COMPONENT_PREVIEW_IFRAME_HEIGHT,
  COMPONENT_PREVIEW_IFRAME_WIDTH,
} from '@/components/componentPreviewSizing';
import { useHeadlessDraftSession } from '@/features/layout/preview/useHeadlessDraftSession';

import type {
  CanvasGeometry,
  CanvasRect,
} from '@drupal-canvas/preview-geometry';
import type { HeadlessSettings } from '@drupal-canvas/types';
import type { JSComponent } from '@/types/Component';

import styles from './ComponentPreview.module.css';

interface HeadlessComponentPreviewProps {
  component: JSComponent;
  settings: HeadlessSettings;
}

function getComponentCrop(
  geometry: CanvasGeometry[],
  iframeHeight: number,
): CanvasRect | null {
  const componentCrops = geometry.flatMap(({ type, rect }) => {
    if (type !== 'component') {
      return [];
    }

    const left = Math.max(
      0,
      Math.min(COMPONENT_PREVIEW_IFRAME_WIDTH, rect.left),
    );
    const top = Math.max(0, Math.min(iframeHeight, rect.top));
    const right = Math.max(
      0,
      Math.min(COMPONENT_PREVIEW_IFRAME_WIDTH, rect.right),
    );
    const bottom = Math.max(0, Math.min(iframeHeight, rect.bottom));
    const width = right - left;
    const height = bottom - top;

    return width > 0 && height > 0
      ? [{ top, right, bottom, left, width, height }]
      : [];
  });

  return (
    componentCrops.sort(
      (first, second) =>
        second.width * second.height - first.width * first.height,
    )[0] ?? null
  );
}

/**
 * Renders an app-owned component through the configured headless frontend.
 */
const HeadlessComponentPreview: React.FC<HeadlessComponentPreviewProps> = ({
  component,
  settings,
}) => {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const { entityId, entityType, previewEntityId } = useParams();
  const { geometry } = useHeadlessDraftSession(
    iframeRef,
    settings,
    entityType,
    entityId ?? previewEntityId,
    undefined,
    COMPONENT_PREVIEW_IFRAME_HEIGHT,
    { componentPreviewId: component.id },
  );
  const componentCrop = getComponentCrop(
    geometry,
    COMPONENT_PREVIEW_IFRAME_HEIGHT,
  );
  const crop = componentCrop ?? {
    top: 0,
    right: COMPONENT_PREVIEW_IFRAME_WIDTH,
    bottom: COMPONENT_PREVIEW_IFRAME_HEIGHT,
    left: 0,
    width: COMPONENT_PREVIEW_IFRAME_WIDTH,
    height: COMPONENT_PREVIEW_IFRAME_HEIGHT,
  };
  const sizing = calculateComponentPreviewSizing(crop.width, crop.height);

  return (
    <div
      className={styles.wrapper}
      style={{
        width: `${sizing.width}px`,
        height: `${sizing.height}px`,
        visibility: componentCrop ? 'visible' : 'hidden',
      }}
      aria-label={`${component.name} headless preview thumbnail`}
    >
      <div
        className={styles.scaled}
        style={{
          width: `${COMPONENT_PREVIEW_IFRAME_WIDTH}px`,
          height: `${COMPONENT_PREVIEW_IFRAME_HEIGHT}px`,
          transform: `translate(${-crop.left * sizing.scale}px, ${-crop.top * sizing.scale}px) scale(${sizing.scale})`,
        }}
      >
        <iframe
          ref={iframeRef}
          title={component.name}
          width={COMPONENT_PREVIEW_IFRAME_WIDTH}
          height={COMPONENT_PREVIEW_IFRAME_HEIGHT}
          data-preview-component-id={component.id}
          className={styles.iframe}
          tabIndex={-1}
        />
      </div>
    </div>
  );
};

export default HeadlessComponentPreview;
