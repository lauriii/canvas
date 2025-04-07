import { useEffect, useCallback } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import type { RegionsMap } from '@/types/AnnotationMaps';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';

/**
 * This hook renders a placeholder div in the preview iFrame in the Content region (if it's empty).
 * @todo https://www.drupal.org/i/3473761 at the moment this leads to a layout shift where the page loads and then
 * suddenly the empty slot placeholders pop into existence.
 * Ideally the back end would render the <div class="xb--slot-empty-placeholder" />
 */

function useRenderPreviewEmptyRegionPlaceholder(
  iframe: HTMLIFrameElement | null,
  regionsMap: RegionsMap,
) {
  const layout = useAppSelector(selectLayout);

  const init = useCallback(() => {
    if (!iframe?.srcdoc) {
      return;
    }

    const defaultRegion = layout.find((r) => r.id === DEFAULT_REGION);
    const defaultRegionInfo = regionsMap[DEFAULT_REGION];
    const regionElement = defaultRegionInfo?.elements[0];

    if (defaultRegion?.components.length === 0 && regionElement) {
      const placeholderDiv = document.createElement('div');
      placeholderDiv.classList.add('xb--region-empty-placeholder');
      const childNodes = Array.from(regionElement.childNodes);
      childNodes.forEach((node) => {
        if (node.nodeType !== Node.COMMENT_NODE) {
          regionElement.removeChild(node);
        }
      });

      // Append the new placeholder div
      regionElement.appendChild(placeholderDiv);
    }
  }, [iframe?.srcdoc, regionsMap, layout]);

  useEffect(() => {
    if (iframe) {
      init();
    }
  }, [iframe, init]);
}

export default useRenderPreviewEmptyRegionPlaceholder;
