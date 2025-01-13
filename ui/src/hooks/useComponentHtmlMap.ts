import { useEffect, useState } from 'react';
import type { ComponentsMap, SlotsMap } from '@/types/AnnotationMaps';
import { mapComponents, mapSlots } from '@/utils/function-utils';

export function useComponentHtmlMap(iframe: HTMLIFrameElement | null) {
  const [componentsMap, setComponentsMap] = useState<ComponentsMap>({});
  const [slotsMap, setSlotsMap] = useState<SlotsMap>({});

  useEffect(() => {
    const iframeDocument = iframe?.contentDocument;
    if (!iframeDocument) {
      return;
    }
    setSlotsMap(mapSlots(iframeDocument));
    setComponentsMap(mapComponents(iframeDocument));
  }, [iframe]);

  return { componentsMap, slotsMap };
}
