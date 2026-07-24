import { discoverCanvasBoundaries } from './markers';

import type {
  CanvasBoundary,
  CanvasGeometry,
  CanvasGeometryRoot,
  CanvasRect,
  CanvasStackDirection,
  MeasureCanvasGeometryOptions,
} from './types';

/** Returns the smallest rectangle containing every non-empty input rectangle. */
export function unionCanvasRects(
  rects: ArrayLike<Pick<DOMRectReadOnly, 'top' | 'right' | 'bottom' | 'left'>>,
): CanvasRect | null {
  let top = Number.POSITIVE_INFINITY;
  let right = Number.NEGATIVE_INFINITY;
  let bottom = Number.NEGATIVE_INFINITY;
  let left = Number.POSITIVE_INFINITY;
  let found = false;

  for (let index = 0; index < rects.length; index += 1) {
    const rect = rects[index];
    if (!rect) {
      continue;
    }
    const width = rect.right - rect.left;
    const height = rect.bottom - rect.top;
    if (
      ![rect.top, rect.right, rect.bottom, rect.left].every(Number.isFinite) ||
      (width === 0 && height === 0)
    ) {
      continue;
    }

    found = true;
    top = Math.min(top, rect.top);
    right = Math.max(right, rect.right);
    bottom = Math.max(bottom, rect.bottom);
    left = Math.min(left, rect.left);
  }

  if (!found) {
    return null;
  }

  return {
    top,
    right,
    bottom,
    left,
    width: right - left,
    height: bottom - top,
  };
}

/** Measures one marker pair in viewport CSS pixels. */
export function measureCanvasBoundary(
  boundary: CanvasBoundary,
): CanvasGeometry | null {
  if (
    !boundary.start.isConnected ||
    !boundary.end.isConnected ||
    boundary.start.ownerDocument !== boundary.end.ownerDocument
  ) {
    return null;
  }

  const rect = measureRange(boundary);
  if (!rect) {
    return null;
  }

  const slotContainer =
    boundary.type === 'slot' &&
    boundary.start.parentElement === boundary.end.parentElement
      ? boundary.start.parentElement
      : null;

  return {
    type: boundary.type,
    id: boundary.id,
    rect,
    markerFormat: boundary.markerFormat,
    ...(boundary.componentUuid
      ? { componentUuid: boundary.componentUuid }
      : {}),
    ...(boundary.slotName ? { slotName: boundary.slotName } : {}),
    ...(slotContainer
      ? { stackDirection: getCanvasStackDirection(slotContainer) }
      : {}),
  };
}

/** Discovers and measures every complete Canvas boundary below a DOM root. */
export function measureCanvasGeometry(
  root: CanvasGeometryRoot,
  options: MeasureCanvasGeometryOptions = {},
): CanvasGeometry[] {
  return discoverCanvasBoundaries(root, options).flatMap((boundary) => {
    const geometry = measureCanvasBoundary(boundary);
    return geometry ? [geometry] : [];
  });
}

/** Detects the primary flex or grid stacking direction of a slot container. */
export function getCanvasStackDirection(
  container: Element,
): CanvasStackDirection {
  const view = container.ownerDocument.defaultView;
  if (!view) {
    return 'vertical';
  }

  let element = container;
  let style = view.getComputedStyle(element);
  if (style.display === 'contents' && element.parentElement) {
    element = element.parentElement;
    style = view.getComputedStyle(element);
  }

  if (style.display.includes('flex')) {
    return style.flexDirection === 'row' ||
      style.flexDirection === 'row-reverse'
      ? 'horizontal-flex'
      : 'vertical-flex';
  }

  if (style.display.includes('grid')) {
    const columns = gridTracks(style.gridTemplateColumns);
    const rows = gridTracks(style.gridTemplateRows);
    if (columns.length > 1) {
      return 'horizontal-grid';
    }
    if (columns.length === 1 || rows.length > 1) {
      return 'vertical-grid';
    }
    if (style.gridAutoFlow.includes('column')) {
      return 'vertical-grid';
    }
    if (style.gridAutoFlow.includes('row')) {
      return 'horizontal-grid';
    }
  }

  return 'vertical';
}

function measureRange(boundary: CanvasBoundary): CanvasRect | null {
  const document = boundary.start.ownerDocument;
  if (!document) {
    return null;
  }

  const rects: CanvasRect[] = [];
  const elementRect = measureSiblingElements(boundary);
  if (elementRect) {
    rects.push(elementRect);
  }

  try {
    const boundaryRange = document.createRange();
    boundaryRange.setStartAfter(boundary.start);
    boundaryRange.setEndBefore(boundary.end);
    const textRange = createTextRange(
      boundaryRange,
      boundary.start,
      boundary.end,
    );
    if (textRange && typeof textRange.getClientRects === 'function') {
      const rect = unionCanvasRects(textRange.getClientRects());
      if (rect) {
        rects.push(rect);
      }
    }
  } catch {
    // Element boxes remain available when Range measurement fails.
  }

  return unionCanvasRects(rects);
}

/** Creates a range containing text between markers without boundary whitespace. */
function createTextRange(
  boundaryRange: Range,
  boundaryStart: Node,
  boundaryEnd: Node,
): Range | null {
  const document = boundaryRange.startContainer.ownerDocument;
  if (!document) {
    return null;
  }

  const walker = document.createTreeWalker(
    boundaryRange.commonAncestorContainer,
    NodeFilter.SHOW_ALL,
  );
  walker.currentNode = boundaryStart;
  let firstNode: Text | null = null;
  let firstOffset = 0;
  let lastNode: Text | null = null;
  let lastOffset = 0;
  let node = walker.nextNode();

  while (node && node !== boundaryEnd) {
    if (node.nodeType === Node.TEXT_NODE) {
      const value = node.nodeValue ?? '';
      const firstContentOffset = value.search(/[^\t\n\f\r ]/u);
      if (firstContentOffset !== -1) {
        firstNode ??= node as Text;
        if (firstNode === node) {
          firstOffset = firstContentOffset;
        }
        lastNode = node as Text;
        lastOffset = value.search(/[\t\n\f\r ]*$/u);
      }
    }
    node = walker.nextNode();
  }

  if (node !== boundaryEnd || !firstNode || !lastNode) {
    return null;
  }

  const textRange = document.createRange();
  textRange.setStart(firstNode, firstOffset);
  textRange.setEnd(lastNode, lastOffset);
  return textRange;
}

function measureSiblingElements(boundary: CanvasBoundary): CanvasRect | null {
  if (boundary.start.parentNode !== boundary.end.parentNode) {
    return null;
  }

  const rects: DOMRect[] = [];
  let node = boundary.start.nextSibling;
  while (node && node !== boundary.end) {
    if (node.nodeType === Node.ELEMENT_NODE) {
      collectElementRects(node as Element, rects);
    }
    node = node.nextSibling;
  }
  return unionCanvasRects(rects);
}

function collectElementRects(element: Element, rects: DOMRect[]): void {
  const clientRects = Array.from(element.getClientRects());
  if (clientRects.length > 0) {
    rects.push(...clientRects);
    return;
  }

  const boundingRect = element.getBoundingClientRect();
  if (boundingRect.width !== 0 || boundingRect.height !== 0) {
    rects.push(boundingRect);
    return;
  }

  Array.from(element.children).forEach((child) => {
    collectElementRects(child, rects);
  });
}

function gridTracks(value: string): string[] {
  if (!value || value === 'none') {
    return [];
  }
  return value
    .split(/\s+/)
    .filter((track) => track !== '0px' && track !== 'auto');
}
