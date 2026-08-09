import { useEffect } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  findSwitchNodes,
  getCaseVariantId,
  getPreviewedVariant,
  getSwitchCases,
} from '@/features/layout/personalizationUtils';
import { usePreviewDom } from '@/features/layout/preview/PreviewDomContext';
import { selectPreviewedVariants } from '@/features/ui/uiSlice';

/**
 * Hides the preview elements of personalization cases that are not the
 * previewed variant. The server renders every case of a switch in the
 * preview, so the editor hides the inactive ones client-side. The effect
 * re-runs whenever the preview DOM map refreshes (the iframe is replaced on
 * every preview round trip), which reapplies the hiding to the new elements.
 */
export function useHideInactiveVariants() {
  const componentsMap = usePreviewDom()?.componentsMap;
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const previewedVariants = useAppSelector(selectPreviewedVariants);

  useEffect(() => {
    if (!componentsMap) {
      return;
    }
    const hiddenElements: HTMLElement[] = [];
    findSwitchNodes(layout).forEach((switchNode) => {
      const activeVariantId = getPreviewedVariant(
        previewedVariants,
        switchNode.uuid,
      );
      getSwitchCases(switchNode).forEach((caseNode) => {
        const isActive = getCaseVariantId(model, caseNode) === activeVariantId;
        const elements = componentsMap[caseNode.uuid]?.elements ?? [];
        elements.forEach((element) => {
          if (isActive) {
            element.style.removeProperty('display');
          } else {
            element.style.setProperty('display', 'none');
            hiddenElements.push(element);
          }
        });
      });
    });
    // Restore visibility on cleanup so a stale hide never sticks to an
    // element after the previewed variant or the DOM map changes.
    return () => {
      hiddenElements.forEach((element) => {
        element.style.removeProperty('display');
      });
    };
  }, [componentsMap, layout, model, previewedVariants]);
}
