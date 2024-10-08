import type React from 'react';
import type { MoveEvent as SortableMoveEvent } from 'sortablejs';

export function customSortableDragImage(
  event: DragEvent | React.DragEvent,
  document: Document,
  name: string,
): void {
  if (document && event) {
    // Create and style the custom drag image
    const customDragImage = document.createElement('div');

    customDragImage.textContent = name || 'Component';
    // CSS here is added inline rather than in css file because the dragImage element may be
    // inserted into the preview iFrame and needs to also be styled in that context.
    customDragImage.style.cssText = `
              all: unset;
              font-family: var(--default-font-family);
              background-color: #166bfd;
              font-size: 12px;
              line-height: 12px;
              color: #fff;
              width: 200px;
              height: 20px;
              padding: 5px 10px;
              border: 2px solid transparent;
              border-right: 2px solid rgba(0,0,0,0.2);
              border-bottom: 2px solid rgba(0,0,0,0.2);
              box-shadow: 2px 2px black;
              opacity: 0.7;
              position: absolute;
              display: flex;
              justify-content: center;
              align-items: center;
              top: -9999px;
              pointer-events: none;
            `;
    document.body.appendChild(customDragImage);

    // Set the custom drag image
    event.dataTransfer?.setDragImage(customDragImage, 0, 0);

    // Remove the custom drag image element after a short delay
    window.requestAnimationFrame(() => {
      document?.body.removeChild(customDragImage);
    });
  }
}

/**
 * Decide if a component can be dropped at the current target based on the distance from the edge of a slot.
 *
 * We require a minimum distance from the slot's vertical edges when dragging a component to ensure that we can place
 * a component between two adjacent components which both have slots when there is no other component in between them.
 * Without this extra logic, depending on the layout of two adjacent components with slots, it can be impossible to place
 * a component between them. This is because if we have no space between the adjacent components with slots, their slots
 * will always be picked up as drop target instead of a spot in their parent slot/root.
 *
 * Can be called from Sortable's `onMove` callback.
 * @see https://github.com/SortableJS/Sortable/blob/1.15.2/README.md?plain=1#L211
 *
 * @param ev - The Sortable.MoveEvent object.
 * @param originalEvent - The original Event object. (This can be expected as a MouseEvent.)
 * @returns - A boolean indicating whether the component can be placed at the current target.
 */
export function isDropTargetInSlotAllowedByEdgeDistance(
  ev: SortableMoveEvent,
  originalEvent: Event | { clientX: number; clientY: number },
  edgeThreshold: number = 20,
): boolean {
  // Get the bounding rect of the target's list — the slot to where the component is being dragged.
  // (This can also be the root.)
  const slotRect = ev.to.getBoundingClientRect();
  // Get all the components in the slot/root.
  const slotComponents = Array.from(ev.to.children);
  // Check if the component we're currently hovering over while dragging is the first or last in the slot.
  const isFirstInSlot = ev.related === slotComponents[0];
  const isLastInSlot = ev.related === slotComponents[slotComponents.length - 1];
  // Check if we're targeting the root instead of a slot inside a component.
  const isRoot = ev.to.getAttribute('data-xb-uuid') === 'root';

  // If the component we're currently hovering over is the first or last component in a slot, and the slot is not the
  // root, make sure that we don't allow the position as a drop target unless we're within a specified distance from
  // the top or bottom of the slot.
  if (
    // originalEvent is expected to be a MouseEvent, but we'll type guard just in case.
    originalEvent &&
    'clientY' in originalEvent &&
    !isRoot &&
    (isFirstInSlot || isLastInSlot)
  ) {
    const distanceFromEdge = isFirstInSlot
      ? originalEvent.clientY - slotRect.top
      : slotRect.bottom - originalEvent.clientY;
    return Math.abs(distanceFromEdge) >= edgeThreshold;
  }
  return true;
}
