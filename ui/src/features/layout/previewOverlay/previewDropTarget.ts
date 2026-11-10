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

// Within this many pixels of an empty slot, in the component that has the
// slot, the pointer targets the slot. A component is carried by where it was
// grabbed, or by its name beside it, so the pointer is often next to the empty
// slot the component is held over.
const EMPTY_SLOT_REACH = 32;

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

const gapX = (r: DOMRect, p: Point) => Math.max(r.left - p.x, 0, p.x - r.right);

const gapY = (r: DOMRect, p: Point) => Math.max(r.top - p.y, 0, p.y - r.bottom);

const distance = (r: DOMRect, p: Point) => Math.hypot(gapX(r, p), gapY(r, p));

const nearest = <T>(of: T[], distanceTo: (one: T) => number) =>
  of.reduce((a, b) => (distanceTo(b) < distanceTo(a) ? b : a));

// Whether a point is in the bounding box of rectangles.
const within = (rects: DOMRect[], p: Point) =>
  rects.some((r) => p.x >= r.left) &&
  rects.some((r) => p.x <= r.right) &&
  rects.some((r) => p.y >= r.top) &&
  rects.some((r) => p.y <= r.bottom);

// How far the pointer is from each of the slots of a component. Slots are as
// large as their content, so below a short column the pointer can be closer
// to the column beside it than to the one it is in. Slots that are all side
// by side are compared by their horizontal distance alone, and slots that are
// all above each other by their vertical distance alone. Any other
// arrangement is compared by distance.
function slotDistance(rects: DOMRect[], p: Point): (r: DOMRect) => number {
  const apart = (from: 'left' | 'top', to: 'right' | 'bottom') =>
    rects.every((a) =>
      rects.every(
        (b) => a === b || a[to] <= b[from] + 1 || b[to] <= a[from] + 1,
      ),
    );
  const beside = apart('left', 'right');
  const above = apart('top', 'bottom');
  if (beside && !above) return (r) => gapX(r, p);
  if (above && !beside) return (r) => gapY(r, p);
  return (r) => distance(r, p);
}

const childrenOf = (el: HTMLElement, selector: string) =>
  Array.from(el.children).filter((child): child is HTMLElement =>
    child.matches(selector),
  );

const childItems = (container: HTMLElement) =>
  childrenOf(container, '[data-canvas-drop-item]');

const isActive = (el: HTMLElement, activeId: UniqueIdentifier) =>
  el.dataset.canvasDropItem === activeId;

// Whether components are laid out in more than one row and column: one is
// beside the first and one is below it. In a single column, none is beside
// another.
const wraps = ([first, ...rest]: DOMRect[]) =>
  rest.some(
    (r) =>
      r.top < first.bottom - 1 &&
      (r.left >= first.right - 1 || r.right <= first.left + 1),
  ) && rest.some((r) => r.top >= first.bottom - 1);

const isHorizontal = (container: HTMLElement) =>
  !!container.dataset.canvasStackDirection?.startsWith('h');

/**
 * Resolves the preview drop zone for a pointer position from the overlay DOM.
 *
 * Instead of hit-testing the thin drop zone bands at component edges, this
 * finds the deepest slot or region under the pointer and the insertion point
 * nearest to the pointer within it, so every pixel of the preview resolves to
 * a drop target. Within a component, except near its edges, the pointer is
 * taken to be in the nearest slot of the component when it is between its
 * slots, near an empty slot, or in the component that holds the dragged
 * component. The result is the id of the existing drop zone that represents
 * that insertion point, so drop handling is unchanged.
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
  const rect = rectReader();
  const id = resolveDropZoneId(root, point, activeId, rect);
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
          rect,
        ) === last,
    )
  ) {
    return last;
  }
  frozen.last = id;
  return id;
}

// The slot or region the pointer is in.
function containerUnder(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
  rect: (el: Element) => DOMRect,
): HTMLElement {
  // The component that holds the dragged component.
  const home = Array.from(
    root.querySelectorAll<HTMLElement>('[data-canvas-drop-item]'),
  )
    .find((el) => isActive(el, activeId))
    ?.parentElement?.closest('[data-canvas-drop-item]');
  let container = root;
  for (;;) {
    const hit = childItems(container).find(
      (el) => !isActive(el, activeId) && contains(rect(el), point),
    );
    if (!hit) return container;
    const r = rect(hit);
    const [start, size, pos] = isHorizontal(container)
      ? [r.left, r.width, point.x]
      : [r.top, r.height, point.y];
    const edge = Math.min(EDGE_THRESHOLD, size / 4);
    if (pos - start < edge || start + size - pos < edge) return container;
    // Slots collapsed to no width or no height are not drop targets.
    const slots = childrenOf(hit, '[data-canvas-drop-container]').filter(
      (el) => rect(el).width && rect(el).height,
    );
    if (!slots.length) return container;
    const rects = slots.map(rect);
    const distanceTo = slotDistance(rects, point);
    const under =
      slots.find((el) => contains(rect(el), point)) ??
      nearest(slots, (el) => distanceTo(rect(el)));
    // Outside the bounding box of its slots, the pointer targets the gap next
    // to the component, unless an empty slot is near or the component holds
    // the dragged component. Between its slots, that gap is across the
    // component from the pointer.
    if (
      !within(rects, point) &&
      hit !== home &&
      !(
        !childItems(under).length &&
        distance(rect(under), point) <= EMPTY_SLOT_REACH
      )
    ) {
      return container;
    }
    container = under;
  }
}

function resolveDropZoneId(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
  rect: (el: Element) => DOMRect,
): string | null {
  const container = containerUnder(root, point, activeId, rect);
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
  const target = nearest(items, (el) => distance(rect(el), point));
  const r = rect(target);
  const after = horizontal
    ? point.x > r.left + r.width / 2
    : point.y > r.top + r.height / 2;
  // In a grid, or a row that wraps, the dragged component takes the place of
  // the component under the pointer, so it is shown where the pointer is.
  // Before or after it would put it in the cell before the pointer from the
  // first half of every cell.
  const index =
    current !== -1 && wraps(all.map(rect))
      ? all.indexOf(target)
      : items.indexOf(target) + (after ? 1 : 0);
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
