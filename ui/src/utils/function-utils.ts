import type { PropsValues } from '@/types/Form';

export function handleNonWorkingBtn(): void {
  alert('Not yet supported.');
}

export const preventHover = (event: any) => {
  const e = event as Event;
  e.preventDefault();
};

export function parseValue(
  value: any,
  element: HTMLInputElement,
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
    return element.checked;
  }
  if (value === '') {
    return value;
  }
  const parsed = Number(value);
  return isNaN(parsed) ? value : parsed;
}

/**
 * Calculates the horizontal and vertical distance between two DOM elements.
 *
 * @param el1 - The first DOM element.
 * @param el2 - The second DOM element.
 * @returns An object containing the horizontal and vertical distances between the elements.
 */
export function getDistanceBetweenElements(
  el1: Element,
  el2: Element,
): { horizontalDistance: number; verticalDistance: number } {
  const rect1 = el1.getBoundingClientRect();
  const rect2 = el2.getBoundingClientRect();

  // Calculate the horizontal and vertical distances
  const dx = rect2.left - rect1.left;
  const dy = rect2.top - rect1.top;

  return {
    horizontalDistance: dx,
    verticalDistance: dy,
  };
}
