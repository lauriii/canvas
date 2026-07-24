import { discoverCanvasBoundaries } from '@drupal-canvas/preview-geometry';

import type { PropsValues } from '@drupal-canvas/types';
import type { PreviewDomMaps } from '@/features/layout/preview/PreviewDomContext';
import type { PendingChanges } from '@/services/pendingChangesApi';

export function handleNonWorkingBtn(): void {
  alert('Not yet supported.');
}

export const preventHover = (event: any) => {
  const e = event as Event;
  e.preventDefault();
};

/**
 * Checks if an array of numbers contains consecutive values (each value is exactly one more than the previous).
 * For example, [1,2,3,4] are consecutive but [1,2,4,5] are not.
 *
 * @param sortedIndexes - Array of numbers in ascending order to check for consecutiveness
 * @returns True if all values are consecutive, or if the array has 0-1 elements. False otherwise.
 */
export function isConsecutive(sortedIndexes: number[]): boolean {
  for (let i = 1; i < sortedIndexes.length; i++) {
    if (sortedIndexes[i] !== sortedIndexes[i - 1] + 1) {
      return false;
    }
  }
  return true;
}
export function parseValue(
  value: any,
  element: HTMLInputElement | HTMLSelectElement,
  schema: PropsValues | null,
) {
  if (schema?.type === 'string') {
    return `${value}`;
  }
  if (schema?.type === 'number') {
    const parsed = Number(value);
    return isNaN(parsed) ? value : parsed;
  }
  if (element && Object.prototype.hasOwnProperty.call(element, 'checked')) {
    return (element as HTMLInputElement).checked;
  }
  if (value === '') {
    return value;
  }
  const parsed = Number(value);
  return isNaN(parsed) ? value : parsed;
}

/**
 * Returns the scroll position to center the scroll exactly half way horizontally.
 * @param parent
 */
export function getHalfwayScrollPosition(parent: HTMLElement | null) {
  if (parent) {
    // Calculate the maximum possible scrollLeft value (total scrollable width).
    const maxScrollLeft = parent.scrollWidth - parent.clientWidth;
    // Return the halfway scroll position.
    return maxScrollLeft / 2;
  }
  return 0;
}

/** Maps component elements from the shared Canvas marker grammar. */
export function mapCanvasDocument(document: Document): PreviewDomMaps {
  const maps: PreviewDomMaps = { componentsMap: {} };
  discoverCanvasBoundaries(document).forEach((boundary) => {
    if (boundary.type === 'component') {
      const elements = getBoundaryElements(boundary.start, boundary.end);
      elements.forEach((element) => {
        element.dataset.canvasUuid = boundary.id;
      });
      maps.componentsMap[boundary.id] = { elements };
    }
  });

  return maps;
}

function getBoundaryElements(start: Node, end: Node): HTMLElement[] {
  if (start.parentNode !== end.parentNode) {
    return [];
  }

  const elements: HTMLElement[] = [];
  let node = start.nextSibling;
  while (node && node !== end) {
    if (node.nodeType === Node.ELEMENT_NODE) {
      elements.push(node as HTMLElement);
    }
    node = node.nextSibling;
  }
  return elements;
}

export function findInChanges(
  changeList: PendingChanges,
  entityId: string | undefined,
  entityType: string | undefined,
) {
  if (!entityId || !entityType || !changeList) {
    return false;
  }
  for (const key in changeList) {
    if (Object.prototype.hasOwnProperty.call(changeList, key)) {
      const obj = changeList[key];
      if (obj.entity_id === entityId && obj.entity_type === entityType) {
        return true;
      }
    }
  }
  return false;
}
