import type { UniqueIdentifier } from '@dnd-kit/core';

// Within this many pixels of a component's leading/trailing edge, the pointer
// targets the gap next to the component instead of the slots inside it. This
// is what lets you drop beside a container that is filled edge to edge by its
// slot.
const EDGE_THRESHOLD = 12;

// Once the drop target changes, it changes again only when the pointer is this
// many pixels past where it would. Otherwise a hand that is held still, which
// is never quite still, moves the dragged component back and forth across the
// boundary between two drop targets.
const HYSTERESIS = 8;

interface Point {
  x: number;
  y: number;
}

// Drop targets as they were laid out when the drag started.
let frozen: {
  viewport: Element;
  origin: DOMRect;
  rects: WeakMap<Element, DOMRect>;
  // The drop target last resolved, if any.
  last?: string | null;
} | null = null;

/**
 * Resolves drop targets against the layout at the start of a drag.
 *
 * The dragged component is shown at its drop position while it is dragged
 * (see livePreview.ts), which moves the other components. Resolving drop
 * targets against where they were keeps the drop target a function of the
 * pointer position alone: otherwise moving the component can put another
 * component under the pointer, which moves it back, and so on.
 *
 * @param viewport
 *   The overlay of the previewed page. It moves with the page when it
 *   scrolls.
 */
export function freezeDropTargets(viewport: Element): void {
  const rects = new WeakMap<Element, DOMRect>();
  viewport
    .querySelectorAll('[data-canvas-drop-item], [data-canvas-drop-container]')
    .forEach((el) => rects.set(el, el.getBoundingClientRect()));
  frozen = { viewport, origin: viewport.getBoundingClientRect(), rects };
}

/** Resolves drop targets against the current layout again. */
export function unfreezeDropTargets(): void {
  frozen = null;
}

// Reads the rectangles of drop targets, as frozen if they are.
function rectReader(): (el: Element) => DOMRect {
  if (!frozen) {
    return (el) => el.getBoundingClientRect();
  }
  const { viewport, origin, rects } = frozen;
  const now = viewport.getBoundingClientRect();
  const dx = now.left - origin.left;
  const dy = now.top - origin.top;
  return (el) => {
    const r = rects.get(el);
    return r
      ? new DOMRect(r.left + dx, r.top + dy, r.width, r.height)
      : el.getBoundingClientRect();
  };
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

const isActive = (el: HTMLElement, activeId: UniqueIdentifier) =>
  el.dataset.canvasDropItem === activeId;

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
 * Over the dragged component, the drop target is where it was. Its slots are
 * not drop targets. During a drag, drop targets are resolved against the
 * layout at its start (see freezeDropTargets()), and change only once the
 * pointer is clearly past the boundary between two of them.
 *
 * @param root
 *   The overlay element of the root region (marked data-canvas-drop-container).
 * @param point
 *   The pointer position in viewport coordinates.
 * @param activeId
 *   The id of the dragged item; equals the component uuid for preview drags.
 *
 * @return
 *   The drop zone id, or NULL for the position the dragged component was at
 *   when the drag started (dropping there is a no-op).
 */
export function findPreviewDropZoneId(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
): string | null {
  const id = resolveDropZoneId(root, point, activeId);
  if (!frozen) {
    return id;
  }
  const { last } = frozen;
  if (
    last !== undefined &&
    id !== last &&
    [
      [1, 0],
      [-1, 0],
      [0, 1],
      [0, -1],
    ].some(
      ([dx, dy]) =>
        resolveDropZoneId(
          root,
          { x: point.x + dx * HYSTERESIS, y: point.y + dy * HYSTERESIS },
          activeId,
        ) === last,
    )
  ) {
    return last;
  }
  frozen.last = id;
  return id;
}

function resolveDropZoneId(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
): string | null {
  const rect = rectReader();
  let container = root;
  for (;;) {
    const hit = childItems(container).find(
      (el) => !isActive(el, activeId) && contains(rect(el), point),
    );
    if (!hit) break;
    const r = rect(hit);
    const [start, size, pos] = isHorizontal(container)
      ? [r.left, r.width, point.x]
      : [r.top, r.height, point.y];
    const edge = Math.min(EDGE_THRESHOLD, size / 4);
    if (pos - start < edge || start + size - pos < edge) break;
    const slot = Array.from(
      hit.querySelectorAll<HTMLElement>(
        ':scope > [data-canvas-drop-container]',
      ),
    ).find((el) => contains(rect(el), point));
    if (!slot) break;
    container = slot;
  }

  const all = childItems(container);
  // Where the dragged component was, in terms of the other components: the
  // overlay keeps the order of the layout while the component is dragged.
  const current = all.findIndex((el) => isActive(el, activeId));
  if (current !== -1 && contains(rect(all[current]), point)) {
    return null;
  }
  const items = all.filter((el) => !isActive(el, activeId));
  if (!items.length) {
    // Empty slots and regions use their own id for their drop zone.
    return current === -1
      ? (container.dataset.canvasDropContainer ?? null)
      : null;
  }

  const horizontal = isHorizontal(container);
  const nearest = items.reduce((a, b) =>
    distance(rect(b), point) < distance(rect(a), point) ? b : a,
  );
  const r = rect(nearest);
  const after = horizontal
    ? point.x > r.left + r.width / 2
    : point.y > r.top + r.height / 2;
  const index = items.indexOf(nearest) + (after ? 1 : 0);
  if (index === current) {
    return null;
  }
  // Only the first component has a leading drop zone; every other gap is
  // represented by the trailing drop zone of the component before it. When
  // the dragged component is first, index 0 is its own position, so items[0]
  // is the first component whenever a leading drop zone is returned.
  return index === 0
    ? `${items[0].dataset.canvasDropItem}_${horizontal ? 'left' : 'top'}`
    : `${items[index - 1].dataset.canvasDropItem}_${horizontal ? 'right' : 'bottom'}`;
}
