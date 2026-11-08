import { useEffect } from 'react';
import { flushSync } from 'react-dom';
import { createCanvasGeometryObserver } from '@drupal-canvas/preview-geometry';

import { LIVE_PREVIEW_ANIMATION } from '@/features/layout/preview/livePreview';
import { usePreviewDomUpdater } from '@/features/layout/preview/PreviewDomContext';
import { usePreviewGeometryUpdater } from '@/features/layout/preview/PreviewGeometryContext';
import { mapCanvasDocument } from '@/utils/function-utils';

export function useComponentHtmlMap(iframe: HTMLIFrameElement | null) {
  const { updateDomMaps, clearDomMaps } = usePreviewDomUpdater();
  const { updateGeometry, clearGeometry } = usePreviewGeometryUpdater();
  const iframeDocument = iframe?.contentDocument ?? null;

  useEffect(() => {
    clearDomMaps();
    clearGeometry();
    const view = iframeDocument?.defaultView;
    if (!iframeDocument?.body || !view) {
      return;
    }

    const refreshDomMaps = () => {
      updateDomMaps(mapCanvasDocument(iframeDocument));
    };
    refreshDomMaps();
    // Observes before the geometry observer does, so the overlay renders the
    // new maps and geometry together.
    const observer = new view.MutationObserver((mutations) => {
      if (mutations.length === 0) {
        return;
      }
      refreshDomMaps();
    });
    observer.observe(iframeDocument, {
      attributes: false,
      characterData: false,
      childList: true,
      subtree: true,
    });

    let observing = false;
    const geometryObserver = createCanvasGeometryObserver({
      root: iframeDocument,
      // Markup moved or added in the preview (e.g. by a drag) paints in this
      // frame: update the overlay before that paint, so outlines and drop
      // targets never lag behind it.
      synchronous: true,
      // Components slide to new positions while something is dragged (see
      // livePreview.ts): follow them frame by frame.
      followAnimation: (animation) => animation.id === LIVE_PREVIEW_ANIMATION,
      onChange: (geometry) =>
        observing
          ? flushSync(() => updateGeometry(geometry))
          : updateGeometry(geometry),
    });
    observing = true;

    return () => {
      observer.disconnect();
      geometryObserver.disconnect();
      clearDomMaps();
      clearGeometry();
    };
  }, [
    clearDomMaps,
    clearGeometry,
    iframeDocument,
    updateDomMaps,
    updateGeometry,
  ]);
}
