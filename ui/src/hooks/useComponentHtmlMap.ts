import { useEffect } from 'react';
import { createCanvasGeometryObserver } from '@drupal-canvas/preview-geometry';

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
