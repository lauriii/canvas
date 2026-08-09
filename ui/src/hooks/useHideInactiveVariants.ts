import { useEffect } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import {
  DEFAULT_VARIANT_ID,
  findSwitchNodes,
  getCaseVariantId,
  getPreviewedVariant,
  getSwitchCases,
  humanizeVariantId,
} from '@/features/layout/personalizationUtils';
import { usePreviewDom } from '@/features/layout/preview/PreviewDomContext';
import { selectPreviewedVariants } from '@/features/ui/uiSlice';

// The preview iframe renders the site theme, which does not define the
// editor's design tokens, so the accent token needs the editor's accent-9
// value as a fallback.
const BOUNDARY_COLOR = 'var(--accent-9, #0090ff)';

// Inline styles of the corner label chip injected into the first element of
// the active case. Inline styles survive the iframe's own stylesheets and
// need no injected stylesheet of their own.
const CHIP_CSS = [
  'position: absolute',
  'top: -4px',
  'left: -4px',
  'transform: translateY(-100%)',
  'z-index: 10000',
  'padding: 1px 6px',
  'border-radius: 3px 3px 3px 0',
  `background: ${BOUNDARY_COLOR}`,
  'color: #fff',
  'font-family: system-ui, sans-serif',
  'font-size: 11px',
  'font-weight: 500',
  'line-height: 16px',
  'white-space: nowrap',
  'pointer-events: none',
].join('; ');

/**
 * Applies the variant editing state to the preview elements of
 * personalization cases. The server renders every case of a switch in the
 * preview, so the editor hides the inactive ones client-side. While the
 * previewed variant of a switch is not the default, the active case's
 * elements additionally get an accent outline and a corner chip naming the
 * variant, marking the boundary of the personalized content. The effect
 * re-runs whenever the preview DOM map refreshes (the iframe is replaced on
 * every preview round trip), which reapplies everything to the new elements.
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
    const outlinedElements: HTMLElement[] = [];
    const injectedChips: HTMLElement[] = [];
    const repositionedElements: { element: HTMLElement; previous: string }[] =
      [];
    findSwitchNodes(layout).forEach((switchNode) => {
      const activeVariantId = getPreviewedVariant(
        previewedVariants,
        switchNode.uuid,
      );
      const showBoundary = activeVariantId !== DEFAULT_VARIANT_ID;
      getSwitchCases(switchNode).forEach((caseNode) => {
        const isActive = getCaseVariantId(model, caseNode) === activeVariantId;
        const elements = componentsMap[caseNode.uuid]?.elements ?? [];
        elements.forEach((element, index) => {
          if (!isActive) {
            element.style.setProperty('display', 'none');
            hiddenElements.push(element);
            return;
          }
          element.style.removeProperty('display');
          if (!showBoundary) {
            return;
          }
          element.style.setProperty('outline', `2px solid ${BOUNDARY_COLOR}`);
          element.style.setProperty('outline-offset', '2px');
          outlinedElements.push(element);
          if (index === 0) {
            // The chip is positioned against the element, which therefore
            // must establish a positioning context.
            const view = element.ownerDocument.defaultView;
            if (view?.getComputedStyle(element).position === 'static') {
              repositionedElements.push({
                element,
                previous: element.style.getPropertyValue('position'),
              });
              element.style.setProperty('position', 'relative');
            }
            const chip = element.ownerDocument.createElement('div');
            chip.dataset.canvasVariantChip = activeVariantId;
            chip.textContent = humanizeVariantId(activeVariantId);
            chip.style.cssText = CHIP_CSS;
            element.appendChild(chip);
            injectedChips.push(chip);
          }
        });
      });
    });
    // Restore every mutation on cleanup so a stale hide, outline, or chip
    // never sticks to an element after the previewed variant or the DOM map
    // changes.
    return () => {
      hiddenElements.forEach((element) => {
        element.style.removeProperty('display');
      });
      outlinedElements.forEach((element) => {
        element.style.removeProperty('outline');
        element.style.removeProperty('outline-offset');
      });
      injectedChips.forEach((chip) => {
        chip.remove();
      });
      repositionedElements.forEach(({ element, previous }) => {
        if (previous) {
          element.style.setProperty('position', previous);
        } else {
          element.style.removeProperty('position');
        }
      });
    };
  }, [componentsMap, layout, model, previewedVariants]);
}
