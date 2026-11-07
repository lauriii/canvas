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
    const geometryObserver = createCanvasGeometryObserver({
      root: iframeDocument,
      onChange: updateGeometry,
    });

    // Components slide to new positions while something is dragged (see
    // livePreview.ts), which changes no markup: follow them frame by frame.
    let frame = 0;
    const sliding = () =>
      iframeDocument
        .getAnimations?.()
        .some(
          (a) => a.id === LIVE_PREVIEW_ANIMATION && a.playState === 'running',
        );
    const follow = () => {
      frame = sliding() ? view.requestAnimationFrame(follow) : 0;
      flushSync(() => updateGeometry(geometryObserver.measure()));
    };

    const observer = new view.MutationObserver((mutations) => {
      if (mutations.length === 0) {
        return;
      }
      // Markup moved or added in the preview (e.g. by a drag) paints in this
      // frame: update the overlay before that paint, instead of in the next
      // animation frame, so outlines and drop targets never lag behind it.
      flushSync(() => {
        refreshDomMaps();
        updateGeometry(geometryObserver.measure());
      });
      if (!frame && sliding()) {
        frame = view.requestAnimationFrame(follow);
      }
    });

    observer.observe(iframeDocument, {
      attributes: false,
      characterData: false,
      childList: true,
      subtree: true,
    });

    return () => {
      observer.disconnect();
      view.cancelAnimationFrame(frame);
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
