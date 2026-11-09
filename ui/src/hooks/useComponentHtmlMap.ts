import { useEffect } from 'react';
import { flushSync } from 'react-dom';
import { createCanvasGeometryObserver } from '@drupal-canvas/preview-geometry';

import {
  isLivePreviewActive,
  LIVE_PREVIEW_ANIMATION,
} from '@/features/layout/preview/livePreview';
import { usePreviewDomUpdater } from '@/features/layout/preview/PreviewDomContext';
import { previewEditCount } from '@/features/layout/preview/previewEdits';
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
    // Whether the markup changed since the maps were made. Observed before the
    // geometry observer observes it, which then renders the maps and the
    // geometry together.
    let stale = false;
    const observer = new view.MutationObserver(() => {
      stale = true;
    });
    observer.observe(iframeDocument, {
      attributes: false,
      // Markers are renamed, e.g. when a component dropped from the library
      // gets its uuid.
      characterData: true,
      childList: true,
      subtree: true,
    });

    let observing = false;
    let edits = previewEditCount();
    const geometryObserver = createCanvasGeometryObserver({
      root: iframeDocument,
      // Markup moved or added in the preview by a drag or an edit paints in
      // this frame: update the overlay before that paint, so outlines and drop
      // targets never lag behind it. Other changes wait for the next frame.
      synchronous: () => {
        const sync = isLivePreviewActive() || previewEditCount() !== edits;
        edits = previewEditCount();
        return sync;
      },
      // Components slide to new positions while something is dragged (see
      // livePreview.ts): follow them frame by frame.
      followAnimation: (animation) => animation.id === LIVE_PREVIEW_ANIMATION,
      onChange: (geometry) => {
        const update = () => {
          if (stale) {
            stale = false;
            refreshDomMaps();
          }
          updateGeometry(geometry);
        };
        if (observing) {
          flushSync(update);
        } else {
          update();
        }
      },
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
