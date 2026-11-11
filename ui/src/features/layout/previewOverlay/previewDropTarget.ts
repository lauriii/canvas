import type { UniqueIdentifier } from '@dnd-kit/core';

// Within this many pixels of a component's leading/trailing edge, or a sixth
// of its size if that is less, the pointer targets the gap next to the
// component instead of the slots inside it. This is what lets you drop beside
// a container that is filled edge to edge by its slot.
const EDGE_THRESHOLD = 12;

// The drop target changes only once the pointer has moved this many pixels
// from where it last changed, or a tenth of the size of the component under
// the pointer if that is less. Otherwise a hand that is held still, which is
// never quite still, moves the dragged component back and forth: showing it
// at a drop position moves the other components, which can put another drop
// position under the pointer. In small components, a larger margin would
// leave no room for the drop targets inside them. The gap next to a
// component, targeted from the edge of the component, needs no such room.
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

const DROP_ELEMENTS = '[data-canvas-drop-item], [data-canvas-drop-container]';

// The drag that drop targets are resolved for.
let drag: {
  viewport: HTMLElement;
  // The slots and regions that were laid out as grids when the drag started.
  grids: WeakSet<Element>;
  // The drop target last resolved, if any, and where in the previewed page
  // the pointer was when it changed.
  last?: string | null;
  changedAt?: Point;
  // The drop target before that one, and where in the previewed page the
  // dragged component was shown at it.
  from?: { id: string | null; shown: DOMRect };
  // Where the empty slot was that is the drop target, from the corner of the
  // component that has the slot. Shown in it, the dragged component can be
  // smaller than its placeholder.
  heldIn?: DOMRect;
  heldBy?: Element;
  // The empty slot.
  heldSlot?: Element;
  // How tall the previewed page was at its tallest with the pointer on it.
  height?: number;
} | null = null;

/**
 * Starts resolving drop targets for a drag.
 *
 * The dragged component is shown at its drop position while it is dragged
 * (see livePreview.ts), which moves the other components. Drop targets are
 * resolved against where everything is shown, so the drop target is what the
 * pointer is on. While the pointer is on the dragged component where it is
 * shown, the drop target stays what it is.
 *
 * @param viewport
 *   The overlay of the previewed page.
 */
export function startDropTargets(viewport: HTMLElement): void {
  const grids = new WeakSet<Element>();
  viewport
    .querySelectorAll<HTMLElement>('[data-canvas-drop-container]')
    .forEach((container) => {
      const rects = childItems(container).map((el) =>
        el.getBoundingClientRect(),
      );
      if (rects.length && wraps(rects)) {
        grids.add(container);
      }
    });
  drag = { viewport, grids };
}

/**
 * Keeps the drop target while the preview is scrolled under the pointer.
 *
 * @param scrolled
 *   How far the preview was scrolled, in pixels, to keep what is under the
 *   pointer where it is (see livePreview.ts).
 */
export function holdDropTargets(scrolled: number): void {
  if (drag?.changedAt) {
    drag.changedAt = { x: drag.changedAt.x, y: drag.changedAt.y + scrolled };
  }
}

/**
 * Resolves the preview drop zone under a pointer position.
 *
 * @param point
 *   The pointer position in viewport coordinates.
 * @param activeId
 *   The id of the dragged item.
 *
 * @return
 *   The drop zone id (see findPreviewDropZoneId()). NULL over the editor pane
 *   outside the previewed page, where nothing is a drop target. Undefined
 *   outside the editor pane.
 */
export function previewDropZoneAt(
  point: Point,
  activeId: UniqueIdentifier,
): string | null | undefined {
  const viewport =
    drag?.viewport ??
    document
      .querySelector('.canvas--viewport-overlay [data-canvas-drop-container]')
      ?.closest('.canvas--viewport-overlay');
  const root = viewport?.querySelector<HTMLElement>(
    '[data-canvas-drop-container]',
  );
  const pane = viewport?.closest('[data-canvas-editor-pane="true"]');
  if (!root || !pane || !contains(pane.getBoundingClientRect(), point)) {
    // Something else decides where the dragged component is shown.
    if (drag) {
      drag.last = drag.changedAt = drag.from = undefined;
      drag.heldIn = drag.heldBy = drag.heldSlot = drag.height = undefined;
    }
    return undefined;
  }
  // Showing the dragged component at a drop position can make the page
  // shorter, which would leave the pointer below the page: off the page, the
  // component is shown where it was, the page is as tall as it was, and the
  // pointer is on it again. The pointer is on the page for as far as the page
  // reached while the pointer was on it.
  const now = viewport!.getBoundingClientRect();
  const page = new DOMRect(
    now.left,
    now.top,
    now.width,
    Math.max(now.height, drag?.height ?? 0),
  );
  if (!contains(page, point)) {
    // The dragged component is shown where it was.
    if (drag) {
      drag.last = null;
      drag.changedAt = { x: point.x - page.left, y: point.y - page.top };
      drag.from = drag.heldIn = drag.heldBy = drag.heldSlot = undefined;
      drag.height = undefined;
    }
    return null;
  }
  if (drag) {
    drag.height = page.height;
  }
  return findPreviewDropZoneId(root, point, activeId);
}

/** Ends resolving drop targets for a drag. */
export function endDropTargets(): void {
  drag = null;
}

// Reads where overlay elements are laid out. While a component is dragged,
// they slide to new positions (see PreviewOverlay.module.css): where they are
// laid out is where they end up, which their styles say.
function rectReader(): (el: Element) => DOMRect {
  const rects = new Map<Element, DOMRect>();
  const read = (el: Element): DOMRect => {
    let rect = rects.get(el);
    if (!rect) {
      const { top, left, width, height } = (el as HTMLElement).style ?? {};
      const parent = (el as HTMLElement).offsetParent as HTMLElement | null;
      if (parent && top && left && width && height) {
        const base = parent.matches(DROP_ELEMENTS)
          ? read(parent)
          : parent.getBoundingClientRect();
        rect = new DOMRect(
          base.left + parent.clientLeft + parseFloat(left),
          base.top + parent.clientTop + parseFloat(top),
          parseFloat(width),
          parseFloat(height),
        );
      } else {
        rect = el.getBoundingClientRect();
      }
      rects.set(el, rect);
    }
    return rect;
  };
  return read;
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
 * The slots of the dragged component are not drop targets. During a drag
 * (see startDropTargets()), the drop target stays what it is while the
 * pointer is on the dragged component where it is shown, and changes only
 * once the pointer has moved clearly from where it last changed. It doesn't
 * change back to what it was before while that would show the dragged
 * component farther from the pointer.
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
  const active = Array.from(
    root.querySelectorAll<HTMLElement>('[data-canvas-drop-item]'),
  ).find((el) => isActive(el, activeId));
  if (!drag) {
    const { id } = resolveDropZoneId(root, point, activeId, rect, { active });
    return id === KEEP ? null : id;
  }
  const { last, changedAt, from, heldIn, heldBy, heldSlot, viewport } = drag;
  // The dragged component where it is shown: for one from the library, its
  // outline.
  const shown =
    active ??
    viewport.querySelector<HTMLElement>('[data-canvas-drop-preview]') ??
    undefined;
  const { id, hit, inner, edge, container } = resolveDropZoneId(
    root,
    point,
    activeId,
    rect,
    { active, shown, last },
  );
  // In the previewed page, which can scroll under the pointer.
  const origin = viewport.getBoundingClientRect();
  const at = { x: point.x - origin.left, y: point.y - origin.top };
  // Picked up, the dragged component is where it was until the pointer has
  // moved, also when it was picked up by the edge of the component it is in.
  if (last === undefined && active && contains(rect(active), point)) {
    drag.last = null;
    drag.changedAt = at;
    return null;
  }
  // The component that has the empty slot moves with what the dragged
  // component is shown in or taken out of, and the slot with it.
  const by = heldBy && rect(heldBy);
  // The drop target stays what it is while the pointer is where the empty
  // slot was that the dragged component is shown in, or near the slot as it
  // is now, holding the dragged component. It also does while the pointer is
  // on the dragged component where it is shown, or nearer to it than to the
  // components beside it, except near the edge of a component: there, the
  // pointer targets the gap next to that component all the same.
  if (
    last !== undefined &&
    ((heldIn &&
      by &&
      contains(heldIn, { x: point.x - by.left, y: point.y - by.top })) ||
      (heldSlot && distance(rect(heldSlot), point) <= EMPTY_SLOT_REACH) ||
      (!edge && (id === KEEP || (shown && contains(rect(shown), point)))))
  ) {
    drag.from = undefined;
    return last;
  }
  if (id === KEEP || id === last) {
    drag.from = undefined;
    return last ?? null;
  }
  // Where the dragged component is shown can put the drop target it was
  // shown at before under the pointer again, and shown there, this one: it
  // stays at the one of the two that shows it nearer to the pointer, instead
  // of going back and forth as the pointer moves on.
  if (
    shown &&
    from &&
    id === from.id &&
    distance(rect(shown), point) < distance(from.shown, at)
  ) {
    return last ?? null;
  }
  if (last !== undefined && changedAt) {
    const margin = (size = Infinity) =>
      edge ? HYSTERESIS : Math.min(HYSTERESIS, size / 10);
    const moved = Math.max(
      Math.abs(at.x - changedAt.x) / margin(hit?.width),
      Math.abs(at.y - changedAt.y) / margin(hit?.height),
    );
    if (moved < 1) {
      return last;
    }
  }
  drag.from = undefined;
  if (shown && last !== undefined) {
    const { left, top, width, height } = rect(shown);
    drag.from = {
      id: last,
      shown: new DOMRect(left - origin.left, top - origin.top, width, height),
    };
  }
  drag.last = id;
  drag.changedAt = at;
  drag.heldIn = drag.heldBy = drag.heldSlot = undefined;
  const holder = container.parentElement?.closest('[data-canvas-drop-item]');
  if (
    inner &&
    holder &&
    id !== null &&
    id === container.dataset.canvasDropContainer
  ) {
    // The empty slot and what is within reach of it in its component, which
    // is where the pointer targets the slot.
    const slot = rect(container);
    const corner = rect(holder);
    const x = Math.max(slot.left - EMPTY_SLOT_REACH, inner.left);
    const y = Math.max(slot.top - EMPTY_SLOT_REACH, inner.top);
    drag.heldBy = holder;
    drag.heldSlot = container;
    drag.heldIn = new DOMRect(
      x - corner.left,
      y - corner.top,
      Math.min(slot.right + EMPTY_SLOT_REACH, inner.right) - x,
      Math.min(slot.bottom + EMPTY_SLOT_REACH, inner.bottom) - y,
    );
  }
  return id;
}

// The drop target stays what it is.
const KEEP: unique symbol = Symbol('keep');

// The dragged component: its overlay element if it is in the layout, what
// shows where it is, and the drop target it is shown at.
interface Dragged {
  active?: HTMLElement;
  shown?: HTMLElement;
  last?: string | null;
}

// The slot or region the pointer is in, the innermost component under the
// pointer, if any, and whether the pointer is near the edge of a component.
function containerUnder(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
  rect: (el: Element) => DOMRect,
  { active }: Dragged,
): { container: HTMLElement; hit?: DOMRect; inner?: DOMRect; edge: boolean } {
  // The component that holds the dragged component.
  const home = active?.parentElement?.closest('[data-canvas-drop-item]');
  let container = root;
  let r: DOMRect | undefined;
  // The component under the pointer without its edges.
  let inner: DOMRect | undefined;
  for (;;) {
    const hit = childItems(container).find(
      (el) => !isActive(el, activeId) && contains(rect(el), point),
    );
    if (!hit) return { container, hit: r, inner, edge: false };
    r = rect(hit);
    const horizontal = isHorizontal(container);
    const [start, size, pos] = horizontal
      ? [r.left, r.width, point.x]
      : [r.top, r.height, point.y];
    const edge = Math.min(EDGE_THRESHOLD, size / 6);
    inner = horizontal
      ? new DOMRect(r.left + edge, r.top, r.width - 2 * edge, r.height)
      : new DOMRect(r.left, r.top + edge, r.width, r.height - 2 * edge);
    if (pos - start < edge || start + size - pos < edge) {
      return { container, hit: r, inner, edge: true };
    }
    // Slots collapsed to no width or no height are not drop targets.
    const slots = childrenOf(hit, '[data-canvas-drop-container]').filter(
      (el) => rect(el).width && rect(el).height,
    );
    if (!slots.length) return { container, hit: r, inner, edge: false };
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
      return { container, hit: r, inner, edge: false };
    }
    container = under;
  }
}

// The slot or region the dragged component is shown in, and before which of
// the other components in it.
function shownIn(
  root: HTMLElement,
  activeId: UniqueIdentifier,
  { active, last }: Dragged,
): { container: Element; index: number } | undefined {
  const others = (container: HTMLElement) =>
    childItems(container).filter((el) => !isActive(el, activeId));
  if (last === undefined || last === null) {
    // Where it was.
    const container = active?.parentElement;
    return container
      ? { container, index: childItems(container).indexOf(active!) }
      : undefined;
  }
  const containers = Array.from(
    root.querySelectorAll<HTMLElement>('[data-canvas-drop-container]'),
  );
  const empty = [root, ...containers].find(
    (el) => el.dataset.canvasDropContainer === last,
  );
  if (empty) {
    return { container: empty, index: 0 };
  }
  const [, uuid, side] = last.match(/^(.+)_(top|left|bottom|right)$/) ?? [];
  const beside = Array.from(
    root.querySelectorAll<HTMLElement>('[data-canvas-drop-item]'),
  ).find((el) => el.dataset.canvasDropItem === uuid);
  const container = beside?.parentElement;
  return container
    ? {
        container,
        index:
          others(container).indexOf(beside!) +
          (side === 'bottom' || side === 'right' ? 1 : 0),
      }
    : undefined;
}

// innermost component under the pointer, if any, and whether the pointer is
// near the edge of a component.
function resolveDropZoneId(
  root: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
  rect: (el: Element) => DOMRect,
  dragged: Dragged,
): {
  id: string | null | typeof KEEP;
  container: HTMLElement;
  hit?: DOMRect;
  inner?: DOMRect;
  edge: boolean;
} {
  const under = containerUnder(root, point, activeId, rect, dragged);
  return {
    ...under,
    id: dropZoneIn(root, under.container, point, activeId, rect, dragged),
  };
}

// The drop zone nearest to the pointer in a slot or region.
function dropZoneIn(
  root: HTMLElement,
  container: HTMLElement,
  point: Point,
  activeId: UniqueIdentifier,
  rect: (el: Element) => DOMRect,
  dragged: Dragged,
): string | null | typeof KEEP {
  const all = childItems(container);
  // Where the dragged component was, in terms of the other components: the
  // overlay keeps the order of the layout while the component is dragged.
  const current = all.findIndex((el) => isActive(el, activeId));
  // Before the dragged component is shown anywhere else.
  if (
    current !== -1 &&
    dragged.last === undefined &&
    contains(rect(all[current]), point)
  ) {
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
  // The dragged component, where it is shown among the others.
  const at = dragged.shown ? shownIn(root, activeId, dragged) : undefined;
  const shown = at?.container === container ? dragged.shown : undefined;
  const candidates = shown ? [...items, shown] : items;
  // In a grid, or a row that wraps, the dragged component takes the place of
  // the component under the pointer, so it is shown where the pointer is.
  // Before or after it would put it in the cell before the pointer from the
  // first half of every cell. It goes after that component when it is shown
  // before it, as the components between them then move back by one cell,
  // and when the pointer is past the last component of a row.
  const grid = drag
    ? drag.grids.has(container)
    : current !== -1 && wraps(all.map(rect));
  let target: HTMLElement;
  let past = false;
  if (grid) {
    // The row the pointer is in, or else the nearest one.
    const gap = Math.min(...candidates.map((el) => gapY(rect(el), point)));
    const row = candidates.filter((el) => gapY(rect(el), point) <= gap + 1);
    target = nearest(row, (el) => gapX(rect(el), point));
    past = point.x > rect(target).right;
  } else {
    const distanceTo = slotDistance(candidates.map(rect), point);
    target = nearest(candidates, (el) => distanceTo(rect(el)));
  }
  // Nearer to the dragged component than to the components beside it: where
  // it is shown is where the pointer is.
  if (target === shown) {
    return KEEP;
  }
  const r = rect(target);
  const after = horizontal
    ? point.x > r.left + r.width / 2
    : point.y > r.top + r.height / 2;
  const from = shown ? at!.index : drag ? undefined : current;
  const index =
    items.indexOf(target) +
    ((
      grid
        ? past || (from !== undefined && from <= items.indexOf(target))
        : after
    )
      ? 1
      : 0);
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
