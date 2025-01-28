import type { PropsValues } from '@/types/Form';
import type {
  SlotsMap,
  ComponentsMap,
  RegionsMap,
} from '@/types/AnnotationMaps';

export function handleNonWorkingBtn(): void {
  alert('Not yet supported.');
}

export const preventHover = (event: any) => {
  const e = event as Event;
  e.preventDefault();
};

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

/**
 * Finds empty slots and inserts a placeholder div in between the xb-slot-start/xb-slot-end comments to show the user
 * where they can drop things.
 * @param listEl
 */
export function insertPlaceholderIfMatchingComments(listEl: HTMLElement) {
  const commentStartPattern = /^\s*xb-slot-start-([\w-]+)\/[\w-]*\s*$/;
  const commentEndPattern = /^\s*xb-slot-end-([\w-]+)\/[\w-]*\s*$/;
  let startCommentIndex = -1;
  let endCommentIndex = -1;
  let startUuid: string | null = null;

  const childNodes = Array.from(listEl.childNodes);

  // Iterate over child nodes to find the start and end comments with matching UUIDs
  childNodes.some((node, i) => {
    if (node.nodeType === Node.COMMENT_NODE) {
      const startMatch = commentStartPattern.exec(node.nodeValue || '');
      const endMatch = commentEndPattern.exec(node.nodeValue || '');

      if (startMatch) {
        startCommentIndex = i;
        startUuid = startMatch[1];
      } else if (endMatch && startUuid === endMatch[1]) {
        endCommentIndex = i;
        return true; // can stop once we find the matching pair
      }
    }
    return false;
  });

  // Check if there are no elements between the start and end comments
  if (startCommentIndex !== -1 && endCommentIndex !== -1) {
    let hasElementsInBetween = false;
    for (let i = startCommentIndex + 1; i < endCommentIndex; i++) {
      if (childNodes[i].nodeType === Node.ELEMENT_NODE) {
        hasElementsInBetween = true;
        break;
      }
    }

    // Insert placeholderDiv if there are no elements between the comments
    if (!hasElementsInBetween) {
      const placeholderDiv = document.createElement('div');
      placeholderDiv.classList.add('xb--sortable-slot-empty-placeholder');
      listEl.insertBefore(placeholderDiv, childNodes[endCommentIndex]);
    }
  }
}

/**
 * Returns a SlotsMap containing all the slots in the passed `document` keyed by the ID of the slot.
 * @param document
 */
export function mapSlots(document: Document): SlotsMap {
  const walker = document.createTreeWalker(
    document.body,
    NodeFilter.SHOW_COMMENT,
  );
  let currentNode = walker.nextNode();
  const slotsMap: SlotsMap = {};

  while (currentNode) {
    // Adjust regex to capture UUID and slot name, ignoring whitespace
    const slotMatch = /^\s*xb-slot-start-([\w-]+)\/([\w-]+)\s*$/.exec(
      currentNode.nodeValue || '',
    );

    if (slotMatch) {
      const uuid = slotMatch[1];
      const slotName = slotMatch[2];
      const slotId = `${uuid}/${slotName}`;

      // Ensure the parent element exists and is an HTMLElement
      if (currentNode.parentElement) {
        currentNode.parentElement.dataset.xbSlotId = slotId;
        slotsMap[slotId] = {
          element: currentNode.parentElement,
          componentUuid: uuid,
          slotName: slotName,
        };
      }
    }

    currentNode = walker.nextNode();
  }

  return slotsMap;
}

/**
 * Returns a ComponentsMap containing all the components in the passed `document` keyed by the ID of the component.
 * @param document
 */
export function mapComponents(document: Document): ComponentsMap {
  const walker = document.createTreeWalker(
    document.body,
    NodeFilter.SHOW_COMMENT,
  );
  let currentNode = walker.nextNode();
  const componentMap: ComponentsMap = {};

  while (currentNode) {
    const startMatch = /^\s*xb-start-([\w-]+)\s*$/.exec(
      currentNode.nodeValue || '',
    );

    if (startMatch) {
      const uuid = startMatch[1];
      let sibling = currentNode.nextSibling;
      componentMap[uuid] = {
        componentUuid: uuid,
        elements: [],
      };

      // Traverse siblings until the end comment is found
      while (
        sibling &&
        !(
          sibling.nodeType === Node.COMMENT_NODE &&
          sibling.nodeValue?.trim() === `xb-end-${uuid}`
        )
      ) {
        if (sibling.nodeType === Node.ELEMENT_NODE) {
          (sibling as HTMLElement).dataset.xbUuid = uuid;
          componentMap[uuid].elements.push(sibling as HTMLElement);
        }
        sibling = sibling.nextSibling;
      }
    }

    currentNode = walker.nextNode();
  }

  return componentMap;
}

export function mapRegions(document: Document): RegionsMap {
  const regionMap: RegionsMap = {};
  // @todo #3499364 This should be using HTML comments rather than a div[data-xb-region] to denote a region in the HTML
  document
    .querySelectorAll<HTMLElement>('[data-xb-region]')
    .forEach((regionEl) => {
      if (regionEl.dataset?.xbRegion) {
        // @todo #3499364 this can hopefully go away once regions are wrapped with HTML comments. Right now the content region
        // has its children directly inside, all other regions contain a div.region which contains the child components.
        if (
          regionEl.firstElementChild &&
          regionEl.firstElementChild.classList.contains('region')
        ) {
          // @todo #3499364 this is a workaround for the current way regions are wrapped.
          regionEl.firstElementChild.setAttribute(
            'data-xb-uuid',
            regionEl.dataset.xbRegion,
          );
          regionEl.firstElementChild.setAttribute(
            'data-xb-region',
            regionEl.dataset.xbRegion,
          );
          regionMap[regionEl.dataset.xbRegion] = {
            element: regionEl.firstElementChild as HTMLElement,
            regionId: regionEl.dataset.xbRegion,
          };
        } else {
          regionMap[regionEl.dataset.xbRegion] = {
            element: regionEl,
            regionId: regionEl.dataset.xbRegion,
          };
        }
      }
    });
  return regionMap;
}

// <!-- xb-start-4737d23d-fa9a-4670-9807-ebf61e049076 -->
/**
 * Returns an array of all HTMLElements that are in between the xb-start-{uuid} and xb-end-{uuid} HTML comments.
 * @param id
 * @param document
 */
export function getElementsByIdInHTMLComment(
  id: string,
  document: Document,
): HTMLElement[] {
  const startMarker = `xb-start-${id}`;
  const endMarker = `xb-end-${id}`;
  const iterator = document.createNodeIterator(
    document,
    NodeFilter.SHOW_COMMENT,
    null,
  );
  const comments = [];
  let currentNode;

  // Collect all comment nodes
  while ((currentNode = iterator.nextNode())) {
    comments.push(currentNode);
  }

  let startIndex = -1;
  let endIndex = -1;

  // Find the start and end comment indices
  comments.forEach((comment, index) => {
    if (comment.nodeValue?.trim() === startMarker) {
      startIndex = index;
    }
    if (comment.nodeValue?.trim() === endMarker) {
      endIndex = index;
    }
  });

  // If both start and end comments are found and in the correct order
  if (startIndex !== -1 && endIndex !== -1 && startIndex < endIndex) {
    const startComment = comments[startIndex];
    const endComment = comments[endIndex];
    const elements = [];

    // Collect elements between the start and end comments
    let currentNode = startComment.nextSibling;
    while (currentNode && currentNode !== endComment) {
      if (currentNode.nodeType === Node.ELEMENT_NODE) {
        elements.push(currentNode as HTMLElement);
      }
      currentNode = currentNode.nextSibling;
    }

    return elements;
  } else {
    // If the comments are not found or not in the correct order, return an empty array
    return [];
  }
}

/**
 * Finds all the xb-slot-start-{any} HTML comments in the whole document and returns an array containing their immediate parent HTMLElements.
 * @param document
 */
export function getSlotParentsByHTMLComments(
  document: Document,
): HTMLElement[] {
  const slotParents: HTMLElement[] = [];
  const walker = document.createTreeWalker(document, NodeFilter.SHOW_COMMENT, {
    acceptNode(node) {
      const commentPattern = /^\s*xb-slot-start-/;
      return commentPattern.test(node.nodeValue || '')
        ? NodeFilter.FILTER_ACCEPT
        : NodeFilter.FILTER_REJECT;
    },
  });
  let currentNode = walker.nextNode();

  // Each time an xb-slot-start comment is found, add the parent HTMLElement to the array
  while (currentNode) {
    if (currentNode.parentElement) {
      slotParents.push(currentNode.parentElement);
    }
    currentNode = walker.nextNode();
  }

  return slotParents;
}

/**
 * Find a given slot by ID using the HTML comment annotations - it will return the immediate parent HTMLElement that
 * contains the <!-- xb-slot-start-{slotId} --> comment.
 * @param slotId
 * @param document
 */
export function getSlotParentElementByIdInHTMLComment(
  slotId: string,
  document: Document,
): HTMLElement | null {
  // regular expression pattern to match comments with the given slot ID
  const commentPattern = new RegExp(`^\\s*xb-slot-start-${slotId}\\b`);
  const walker = document.createTreeWalker(document, NodeFilter.SHOW_COMMENT, {
    acceptNode(node) {
      return commentPattern.test(node.nodeValue || '')
        ? NodeFilter.FILTER_ACCEPT
        : NodeFilter.FILTER_REJECT;
    },
  });
  let currentNode = walker.nextNode();

  // Traverse the nodes to find the first matching comment
  while (currentNode) {
    if (currentNode.parentElement) {
      return currentNode.parentElement;
    }
    currentNode = walker.nextNode();
  }

  // Return null if no matching comment is found
  return null;
}
