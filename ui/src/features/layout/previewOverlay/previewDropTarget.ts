import type { UniqueIdentifier } from '@dnd-kit/core';

// Within this many pixels of a component's leading/trailing edge, the pointer
// targets the gap next to the component instead of the slots inside it. This
// is what lets you drop beside a container that is filled edge to edge by its
// slot.
const EDGE_THRESHOLD = 12;

interface Point {
  x: number;
  y: number;
}

const contains = (r: DOMRect, p: Point) =>
  p.x >= r.left && p.x <= r.right && p.y >= r.top && p.y <= r.bottom;

const distance = (r: DOMRect, p: Point) =>
  Math.hypot(
    Math.max(r.left - p.x, 0, p.x - r.right),
    Math.max(r.top - p.y, 0, p.y - r.bottom),
  );

const childItems = (container: HTMLElement) =>
  Array.from(
    container.querySelectorAll<HTMLElement>(':scope > [data-canvas-drop-item]'),
  );

const isHorizontal = (container: HTMLElement) =>
  !!container.dataset.canvasStackDirection?.startsWith('h');

/**
 * Resolves the preview drop zone for a pointer position from the overlay DOM.
 *
 * Instead of hit-testing the thin drop zone bands at component edges, this
 * finds the deepest slot or region under the pointer and the insertion point
 * nearest to the pointer within it, so every pixel of the preview resolves to
 * a drop target. The result is the id of the existing drop zone that
 * represents that insertion point, so drop handling is unchanged.
 *
 * @param root
 *   The overlay element of the root region (marked data-canvas-drop-container).
 * @param point
 *   The pointer position in viewport coordinates.
 * @param activeId
 *   The id of the dragged item; equals the component uuid for preview drags.
 *
 * @return
 *   The drop zone id, or NULL when the pointer is over the dragged component
 *   or the gaps directly next to it (dropping there is a no-op).
 */
export function findPreviewDropZoneId(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
): string | null {
  let container = root;
  for (;;) {
    const hit = childItems(container).find((el) =>
      contains(el.getBoundingClientRect(), point),
    );
    if (!hit || hit.dataset.canvasDropItem === activeId) break;
    const r = hit.getBoundingClientRect();
    const [start, size, pos] = isHorizontal(container)
      ? [r.left, r.width, point.x]
      : [r.top, r.height, point.y];
    const edge = Math.min(EDGE_THRESHOLD, size / 4);
    if (pos - start < edge || start + size - pos < edge) break;
    const slot = Array.from(
      hit.querySelectorAll<HTMLElement>(
        ':scope > [data-canvas-drop-container]',
      ),
    ).find((el) => contains(el.getBoundingClientRect(), point));
    if (!slot) break;
    container = slot;
  }

  const items = childItems(container);
  if (!items.length) {
    // Empty slots and regions use their own id for their drop zone.
    return container.dataset.canvasDropContainer ?? null;
  }

  const horizontal = isHorizontal(container);
  const nearest = items.reduce((a, b) =>
    distance(b.getBoundingClientRect(), point) <
    distance(a.getBoundingClientRect(), point)
      ? b
      : a,
  );
  const r = nearest.getBoundingClientRect();
  const after = horizontal
    ? point.x > r.left + r.width / 2
    : point.y > r.top + r.height / 2;
  const index = items.indexOf(nearest) + (after ? 1 : 0);
  const before = items[index - 1]?.dataset.canvasDropItem;
  if (
    before === activeId ||
    items[index]?.dataset.canvasDropItem === activeId
  ) {
    return null;
  }
  // Only the first component has a leading drop zone; every other gap is
  // represented by the trailing drop zone of the component before it.
  return index === 0
    ? `${items[0].dataset.canvasDropItem}_${horizontal ? 'left' : 'top'}`
    : `${before}_${horizontal ? 'right' : 'bottom'}`;
}
