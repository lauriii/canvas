import { useEffect } from 'react';

import { useDataToHtmlMapUpdater } from '@/features/layout/preview/DataToHtmlMapContext';
import { mapComponents, mapRegions, mapSlots } from '@/utils/function-utils';

export function useComponentHtmlMap(iframe: HTMLIFrameElement | null) {
  const { updateRegionsMap, updateComponentsMap, updateSlotsMap } =
    useDataToHtmlMapUpdater();

  const pendingTemplates = iframe?.contentDocument?.querySelectorAll(
    'template[data-astro-template]',
  ).length;

  useEffect(() => {
    const iframeDocument = iframe?.contentDocument;
    if (!iframeDocument || !iframeDocument.body) {
      return;
    }

    // Initial mapping
    updateRegionsMap(mapRegions(iframeDocument));
    updateComponentsMap(mapComponents(iframeDocument));
    updateSlotsMap(mapSlots(iframeDocument));

    const observer = new MutationObserver((mutations) => {
      if (mutations.length === 0) {
        return;
      }
      updateRegionsMap(mapRegions(iframeDocument));
      updateComponentsMap(mapComponents(iframeDocument));
      updateSlotsMap(mapSlots(iframeDocument));
    });

    observer.observe(iframeDocument, {
      attributes: false,
      characterData: false,
      childList: true,
      subtree: true,
    });

    return () => {
      observer.disconnect();
    };
  }, [
    iframe,
    updateComponentsMap,
    updateRegionsMap,
    updateSlotsMap,
    pendingTemplates,
  ]);
}
