import { useEffect, useCallback } from 'react';
import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findSlotById } from '@/features/layout/layoutUtils';
import type { SlotsMap, SlotInfo } from '@/types/Annotations';

/**
 * This hook renders a placeholder div in each empty component slot on the page in the preview iFrame.
 * @todo https://www.drupal.org/i/3473761 at the moment this leads to a layout shift where the page loads and then
 * suddenly the empty slot placeholders pop into existence.
 * Ideally the back end would render the <div class="xb--slot-empty-placeholder" />
 */

function useRenderPreviewEmptySlotPlaceholders(
  iframe: HTMLIFrameElement | null,
  slotsMap: SlotsMap,
) {
  const layout = useAppSelector(selectLayout);

  const init = useCallback(() => {
    if (!iframe?.srcdoc) {
      return;
    }

    const emptySlots = Object.entries(slotsMap).reduce<SlotInfo[]>(
      (arr, [, slot]) => {
        const foundSlot = findSlotById(
          layout,
          `${slot.componentUuid}/${slot.slotName}`,
        );
        if (foundSlot?.components.length === 0) {
          arr.push(slot);
        }
        return arr;
      },
      [],
    );

    emptySlots.forEach((emptySlot) => {
      const placeholderDiv = document.createElement('div');
      placeholderDiv.classList.add('xb--slot-empty-placeholder');
      const childNodes = Array.from(emptySlot.element.childNodes);
      childNodes.forEach((node) => {
        if (node.nodeType !== Node.COMMENT_NODE) {
          emptySlot.element.removeChild(node);
        }
      });

      // Append the new placeholder div
      emptySlot.element.appendChild(placeholderDiv);
    });
  }, [iframe?.srcdoc, slotsMap, layout]);

  useEffect(() => {
    if (iframe) {
      init();
    }
  }, [iframe, init]);
}

export default useRenderPreviewEmptySlotPlaceholders;
