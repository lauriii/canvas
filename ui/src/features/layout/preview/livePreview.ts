import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
  parseCanvasCommentMarker,
} from '@drupal-canvas/preview-geometry';

import {
  bounds,
  boxes,
  canAnimate,
  dropGhost,
  isElement,
  liftGhost,
  outlineEmptySlots,
  SLIDE,
  timing,
  toPreview,
} from '@/features/layout/preview/livePreviewGhost';
import {
  findBoundary,
  hidePlaceholders,
  siblingsBetween,
} from '@/features/layout/preview/optimisticPreview';
import { notePreviewEdit } from '@/features/layout/preview/previewEdits';
import { holdDropTargets } from '@/features/layout/previewOverlay/previewDropTarget';

import type { CanvasBoundary } from '@drupal-canvas/preview-geometry';
import type { Ghost, Point } from '@/features/layout/preview/livePreviewGhost';

/**
 * Shows the dragged component at its drop position while it is dragged.
 *
 * Moving a component moves its markup in the preview; dragging one from the
 * library inserts its default markup. The page reflows around it, so what you
 * see is what you get when you let go. A drop commits the preview; otherwise
 * ending it puts the markup back where it was.
 *
 * A copy of the dragged component follows the pointer every frame: lifted
 * off a moved component, or appearing once a component from the library is
 * first shown in the preview. The component itself stays dimmed where it will
 * be dropped. When that changes, components slide to their new positions instead
 * of jumping there.
 *
 * Drop positions are drop zone ids, as resolved by findPreviewDropZoneId().
 */

const PLACEHOLDER = {
  slot: CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  region: CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
};

/**
 * The component id of markup inserted from the library while it is dragged.
 *
 * The overlay outlines it like a dragged component (see DropPreviewOverlay).
 */
export const DROP_PREVIEW_ID = 'canvas-drop-preview';

/**
 * The id of the animations that slide components to new positions.
 *
 * The overlay waits for them to finish (see useComponentHtmlMap()).
 */
export const LIVE_PREVIEW_ANIMATION = 'canvas-live-preview';

// Where the pointer holds the copy of a component from the library.
const GRAB = { x: 12, y: 12 };

interface Position {
  parent: Node;
  before: Node | null;
  // The empty slot or region the position is in.
  container?: CanvasBoundary;
}

interface Session {
  document: Document;
  // The dragged markup: contiguous siblings.
  nodes: Node[];
  // Where the markup was when the drag started, NULL if it is new.
  home: Position | null;
  // The slot or region the markup was in when the drag started.
  homeContainer?: { type: 'slot' | 'region'; id: string };
  // The size of the markup when the drag started.
  homeSize?: DOMRect;
  // Reverts what showing the markup at its current position changed.
  undo: Array<() => void>;
  // The pointer position, in viewport coordinates of the editor.
  pointer?: Point;
  untrack?: () => void;
  // The copy of the dragged markup that follows the pointer.
  ghost?: Ghost;
  // The drop position the markup is shown at, NULL where it was.
  shown?: string | null;
  // Where the markup is shown, once it has slid there.
  shownAt?: DOMRect;
  // Dim the moved markup.
  dim: Animation[];
  // Slide components to the position the markup is shown at.
  slide: Animation[];
}

let session: Session | null = null;

/** Whether the dragged component is shown at its drop position. */
export const isLivePreviewActive = () => session !== null;

/** Whether a copy of the dragged component follows the pointer. */
export const isLivePreviewCopyShown = () => !!session?.ghost;

/**
 * Resolves the drop position under a pointer position, in viewport
 * coordinates of the editor. Undefined where the preview has none to offer.
 */
export type DropPositionAt = (pointer: Point) => string | null | undefined;

// Keeps track of the pointer while the session lasts, and shows the markup at
// the drop position under it in the frame the pointer gets there. Drag and
// drop libraries resolve it later, once their state is updated.
function track(current: Session, pointer: Point, at?: DropPositionAt) {
  current.pointer = pointer;
  const move = (event: PointerEvent) => {
    current.pointer = { x: event.clientX, y: event.clientY };
  };
  window.addEventListener('pointermove', move, true);
  // The drop position this loop showed last. Only when it changes is the
  // markup shown somewhere else: something else may have shown it elsewhere.
  let shown: string | null | undefined;
  let frame = 0;
  const showAtPointer = () => {
    // Before the copy follows the pointer in the same frame.
    frame = requestAnimationFrame(showAtPointer);
    const id = current.pointer && at?.(current.pointer);
    if (id !== undefined && id !== shown) {
      shown = id;
      showLivePreviewAt(id);
    }
  };
  if (at) {
    frame = requestAnimationFrame(showAtPointer);
  }
  current.untrack = () => {
    window.removeEventListener('pointermove', move, true);
    cancelAnimationFrame(frame);
  };
}

// The elements of components, which slide to new positions.
const componentElements = (boundaries: CanvasBoundary[]) =>
  boundaries
    .filter((b) => b.type === 'component')
    .flatMap((b) => siblingsBetween(b.start, b.end).filter(isElement))
    .flatMap(boxes);

/**
 * Slides elements from where they were shown to where they are now.
 *
 * @param before
 *   Where the elements were shown, including any slide that was underway.
 * @param animations
 *   The animations of the slide are added to this.
 * @param scrolled
 *   How far the preview was scrolled since, in pixels of the preview.
 */
function slide(
  before: Map<HTMLElement, DOMRect>,
  animations: Animation[],
  scrolled = 0,
) {
  const moved = new Map<HTMLElement, Point>();
  before.forEach((was, el) => {
    if (!el.isConnected) {
      return;
    }
    const is = el.getBoundingClientRect();
    moved.set(el, { x: was.left - is.left, y: was.top - is.top + scrolled });
  });
  moved.forEach((delta, el) => {
    // Nested components move with the component they are in.
    let parent = el.parentElement;
    while (parent && !moved.has(parent)) {
      parent = parent.parentElement;
    }
    const outer = parent ? moved.get(parent)! : { x: 0, y: 0 };
    const x = delta.x - outer.x;
    const y = delta.y - outer.y;
    if (Math.abs(x) < 0.5 && Math.abs(y) < 0.5) {
      return;
    }
    animations.push(
      el.animate([{ translate: `${x}px ${y}px` }, { translate: '0 0' }], {
        ...timing(),
        id: LIVE_PREVIEW_ANIMATION,
      }),
    );
  });
}

function isComponentStart(node: Node): boolean {
  if (node.nodeType === Node.COMMENT_NODE) {
    const marker = parseCanvasCommentMarker(node.nodeValue ?? '');
    return marker?.type === 'component' && marker.position === 'start';
  }
  return (
    node.nodeType === Node.ELEMENT_NODE &&
    (node as Element).matches(
      'template[data-canvas-marker="start"][data-canvas-type="component"]',
    )
  );
}

/**
 * Starts showing an existing component at its drop position.
 *
 * @param document
 *   The preview document.
 * @param uuid
 *   The dragged component.
 * @param homeContainer
 *   The slot or region the component is in.
 * @param pointer
 *   Where the component was picked up, in viewport coordinates of the editor.
 *   A copy of the component follows the pointer from there.
 */
export function startMovePreview(
  document: Document,
  uuid: string,
  homeContainer: { type: 'slot' | 'region'; id: string },
  pointer?: Point,
  at?: DropPositionAt,
): void {
  endLivePreview();
  const boundary = findBoundary(
    discoverCanvasBoundaries(document),
    'component',
    uuid,
  );
  const nodes = boundary ? siblingsBetween(boundary.start, boundary.end) : [];
  if (!nodes.length || !boundary?.start.parentNode) {
    return;
  }
  session = {
    document,
    nodes,
    home: {
      parent: boundary.start.parentNode,
      before: boundary.end.nextSibling,
    },
    homeContainer,
    homeSize: bounds(nodes),
    undo: [],
    dim: [],
    slide: [],
  };
  if (pointer && canAnimate(document)) {
    track(session, pointer, at);
    lift(session);
  }
}

// Lifts a copy of the dragged markup under the pointer, dimming the markup.
function lift(current: Session, grab?: Point) {
  current.ghost = liftGhost(
    current.document,
    current.nodes,
    () => current.pointer,
    grab,
  );
  if (current.ghost) {
    current.dim = current.nodes
      .filter(isElement)
      .flatMap(boxes)
      .map((el) =>
        el.animate([{ opacity: '0.4' }], { ...timing(), fill: 'forwards' }),
      );
  }
}

/**
 * Starts showing a component from the library at its drop position.
 *
 * @param document
 *   The preview document.
 * @param markup
 *   The default markup of the component. A labeled box is shown without it,
 *   and instead of markup that can't render in the preview document.
 * @param name
 *   The name of the component.
 * @param pointer
 *   Where the drag started, in viewport coordinates of the editor. Once the
 *   markup is shown in the preview, a copy of it follows the pointer.
 */
export function startInsertPreview(
  document: Document,
  markup: string | undefined,
  name: string,
  pointer?: Point,
  at?: DropPositionAt,
): void {
  endLivePreview();
  const template = document.createElement('template');
  // Template content is inert: scripts in it don't run.
  template.innerHTML = markup ?? '';
  // Custom elements the document doesn't define render nothing, e.g. code
  // components on a page that has none of them yet.
  const renders =
    markup &&
    !Array.from(template.content.querySelectorAll('*')).some(
      (el) =>
        el.localName.includes('-') &&
        !document.defaultView?.customElements.get(el.localName),
    );
  if (!renders) {
    template.innerHTML = '';
    const box = document.createElement('div');
    box.textContent = name;
    box.style.cssText =
      'display:flex;align-items:center;justify-content:center;min-height:48px;font:500 13px/1 system-ui,sans-serif;color:#6e56cf;background:#f5f2ff;border-radius:4px;';
    template.content.append(box);
  }
  outlineEmptySlots(template.content);
  const marker = (position: 'start' | 'end') =>
    document.createComment(
      formatCanvasCommentMarker({
        type: 'component',
        position,
        id: DROP_PREVIEW_ID,
      }),
    );
  const nodes = [
    marker('start'),
    ...Array.from(template.content.childNodes),
    marker('end'),
  ];
  session = {
    document,
    nodes,
    home: null,
    undo: [],
    dim: [],
    slide: [],
  };
  if (pointer && canAnimate(document)) {
    track(session, pointer, at);
  }
}

function resolve(
  boundaries: CanvasBoundary[],
  dropZoneId: string,
): Position | null {
  const match = dropZoneId.match(/^(.+)_(top|left|bottom|right)$/);
  const component = match && findBoundary(boundaries, 'component', match[1]);
  if (match && component) {
    return ['top', 'left'].includes(match[2])
      ? { parent: component.start.parentNode!, before: component.start }
      : {
          parent: component.end.parentNode!,
          before: component.end.nextSibling,
        };
  }
  // An empty slot or region.
  const container =
    findBoundary(boundaries, 'slot', dropZoneId) ??
    findBoundary(boundaries, 'region', dropZoneId);
  return container?.end.parentNode
    ? { parent: container.end.parentNode, before: container.end, container }
    : null;
}

const hasSize = (node: Node) =>
  node.nodeType === Node.ELEMENT_NODE &&
  [node as Element, ...(node as Element).querySelectorAll('*')].some((el) => {
    const { width, height } = el.getBoundingClientRect();
    return width > 0 && height > 0;
  });

/**
 * Shows the dragged component at a drop position.
 *
 * @param dropZoneId
 *   The drop zone id of the position, or NULL to show the component where it
 *   was when the drag started (or not at all if it is new).
 */
// The innermost component under the pointer, other than the dragged one, and
// where it is in the editor viewport.
function anchorAt({ document, nodes, pointer }: Session) {
  const point = pointer && toPreview(document, pointer);
  if (!point) {
    return null;
  }
  const under = componentElements(discoverCanvasBoundaries(document)).filter(
    (el) => {
      const r = el.getBoundingClientRect();
      return (
        !nodes.some((node) => node.contains(el)) &&
        point.x >= r.left &&
        point.x <= r.right &&
        point.y >= r.top &&
        point.y <= r.bottom
      );
    },
  );
  const el = under.find(
    (one) => !under.some((other) => other !== one && one.contains(other)),
  );
  return el ? { el, top: viewportTop(el) } : null;
}

const viewportTop = (el: HTMLElement) => {
  const frame = el.ownerDocument.defaultView!.frameElement as HTMLElement;
  const r = frame.getBoundingClientRect();
  return r.top + (el.getBoundingClientRect().top * r.width) / frame.offsetWidth;
};

/**
 * Scrolls the preview so that a component stays where it was in the viewport.
 *
 * Showing the dragged component in another slot or region moves the
 * components after where it was and after where it is shown. The component
 * under the pointer stays under the pointer, instead of moving away from the
 * pointer as the pointer moves toward it: a large component moved into a slot
 * below it would move that slot up by its own height. The drop target doesn't
 * change for it (see holdDropTargets()).
 *
 * @return
 *   How far the preview was scrolled, in pixels of the preview.
 */
function keepInPlace(anchor: { el: HTMLElement; top: number }): number {
  const frame = anchor.el.ownerDocument.defaultView!
    .frameElement as HTMLElement;
  const pane = frame.closest<HTMLElement>('[data-canvas-editor-pane]');
  const moved = viewportTop(anchor.el) - anchor.top;
  if (!pane || Math.abs(moved) < 0.5) {
    return 0;
  }
  const from = pane.scrollTop;
  pane.scrollTop = from + moved;
  const scrolled = pane.scrollTop - from;
  holdDropTargets(scrolled);
  // The overlay scrolls with the preview at once, but slides its outlines to
  // their new positions (see PreviewOverlay.module.css): offset it so its
  // outlines slide from where they were, like the components do.
  pane
    .querySelector<HTMLElement>(
      '.canvas--viewport-overlay [data-canvas-drop-container]',
    )
    ?.animate(
      [{ translate: `0 ${scrolled}px` }, { translate: '0 0' }],
      timing(),
    );
  return (scrolled * frame.offsetWidth) / frame.getBoundingClientRect().width;
}

export function showLivePreviewAt(dropZoneId: string | null): void {
  // Showing the markup where it is shown would only move it in place.
  if (!session || session.shown === dropZoneId) {
    return;
  }
  session.shown = dropZoneId;
  const { document, nodes } = session;
  // Where components are shown, mid-slide or not, to slide them from there.
  const before = new Map<HTMLElement, DOMRect>();
  if (canAnimate(document)) {
    componentElements(discoverCanvasBoundaries(document)).forEach((el) =>
      before.set(el, el.getBoundingClientRect()),
    );
    // Markup that isn't shown yet has no place to slide from.
    nodes
      .filter((node) => isElement(node) && node.isConnected)
      .flatMap((node) => boxes(node as HTMLElement))
      .forEach((el) => before.set(el, el.getBoundingClientRect()));
  }
  session.slide.forEach((animation) => animation.cancel());
  session.slide = [];
  const anchor = anchorAt(session);
  // Where the markup is shown, if anywhere.
  const from = nodes[0].isConnected ? nodes[0].parentNode : null;
  let scrolled = 0;
  try {
    place(session, dropZoneId);
  } finally {
    session.shownAt = bounds(nodes);
    // Within a slot or region, the components the markup passes make room
    // for it under the pointer. So does a component it is shown right
    // before: nothing needs to be kept in place when the markup is shown
    // under the pointer.
    const pointer = session.pointer && toPreview(document, session.pointer);
    const under =
      pointer &&
      pointer.x >= session.shownAt.left &&
      pointer.x <= session.shownAt.right &&
      pointer.y >= session.shownAt.top &&
      pointer.y <= session.shownAt.bottom;
    if (anchor && !under && from && nodes[0].parentNode !== from) {
      scrolled = keepInPlace(anchor);
      session.shownAt = bounds(nodes);
    }
    // The slides start after the overlay is updated from the moved markup (see
    // useComponentHtmlMap()): a slide would show it where it was.
    const current = session;
    const animations = session.slide;
    queueMicrotask(() => {
      // Unless the markup was shown somewhere else since, or dropped.
      if (current.slide === animations) {
        slide(before, animations, scrolled);
      }
    });
  }
  // The copy of inserted markup appears once the markup can be measured.
  if (!session.home && !session.ghost && session.pointer) {
    lift(session, GRAB);
  }
}

// Moves a node, keeping the state of what is in it where the browser can, e.g.
// so embedded iframes don't reload.
function move(node: Node, parent: Node, before: Node | null) {
  const moveBefore = (
    parent as Node & { moveBefore?: (node: Node, child: Node | null) => void }
  ).moveBefore;
  if (moveBefore && node.isConnected && parent.isConnected) {
    try {
      moveBefore.call(parent, node, before);
      return;
    } catch {
      // E.g. the node is in another document.
    }
  }
  parent.insertBefore(node, before);
}

function place(current: Session, dropZoneId: string | null) {
  const { document, nodes, home, homeContainer } = current;
  current.undo.forEach((revert) => revert());
  current.undo = [];

  const boundaries = discoverCanvasBoundaries(document);
  // Positions outside the preview (e.g. in the layers panel) show the
  // component where it was.
  const target = (dropZoneId && resolve(boundaries, dropZoneId)) || home;
  if (!target) {
    nodes.forEach((node) => node.parentNode?.removeChild(node));
    return;
  }
  if (!target.before || !nodes.includes(target.before)) {
    nodes.forEach((node) => move(node, target.parent, target.before));
  }
  // The markup replaces the placeholder of an empty container, unless it
  // renders nothing yet: the container would collapse.
  if (target.container && (home || nodes.some(hasSize))) {
    hidePlaceholders(target.container, current.undo);
  }

  // Keep the container the component left targetable: an empty one would
  // collapse, so it gets the placeholder it has when it is empty.
  const left =
    homeContainer &&
    target !== home &&
    findBoundary(boundaries, homeContainer.type, homeContainer.id);
  if (
    left &&
    !siblingsBetween(left.start, left.end).slice(1).some(isComponentStart)
  ) {
    const placeholder = document.createElement('div');
    placeholder.className = PLACEHOLDER[homeContainer.type];
    left.end.parentNode!.insertBefore(placeholder, left.end);
    current.undo.push(() => placeholder.remove());
    // No larger than the component was: a container that grows when the
    // component leaves it would put itself back under the pointer.
    const size = placeholder.getBoundingClientRect();
    const { width = 0, height = 0 } = current.homeSize ?? {};
    if (width && width < size.width) {
      placeholder.style.width = placeholder.style.minWidth = `${width}px`;
    }
    if (height && height < size.height) {
      placeholder.style.height = placeholder.style.minHeight = `${height}px`;
    }
  }
}

/**
 * Keeps the dragged markup where it is shown, ending the live preview.
 *
 * @param uuid
 *   The uuid of the component the markup inserted from the library belongs
 *   to. Without it, inserted markup stays outlined until the next preview
 *   render replaces it.
 *
 * @return
 *   The preview document, or NULL if there was no live preview or it doesn't
 *   show the markup, e.g. because the drop was outside the preview.
 */
export function commitLivePreview(uuid?: string): Document | null {
  if (!session) {
    return null;
  }
  const { document, nodes, home } = session;
  // The dropped markup is shown where it lands at once, so the overlay can
  // outline it there right away.
  session.slide.forEach((animation) => animation.finish());
  session.slide = [];
  release(session);
  session = null;
  if (!nodes[0].isConnected) {
    return null;
  }
  if (
    !home ||
    nodes[0].parentNode !== home.parent ||
    nodes[nodes.length - 1].nextSibling !== home.before
  ) {
    notePreviewEdit();
  }
  if (!home && uuid) {
    nodes[0].nodeValue = formatCanvasCommentMarker({
      type: 'component',
      position: 'start',
      id: uuid,
    });
    nodes[nodes.length - 1].nodeValue = formatCanvasCommentMarker({
      type: 'component',
      position: 'end',
      id: uuid,
    });
  }
  return document;
}

/** Stops the live preview, putting the dragged markup back where it was. */
export function endLivePreview(): void {
  if (!session) {
    return;
  }
  showLivePreviewAt(null);
  release(session);
  session = null;
}

// Settles the copy of the dragged markup onto it and stops dimming it.
function release({ ghost, dim, nodes, shownAt, untrack }: Session) {
  untrack?.();
  dim.forEach((animation) => animation.reverse());
  if (ghost) {
    dropGhost(ghost, shownAt ?? bounds(nodes));
    // Not on the animation finishing: that never happens in a hidden tab.
    setTimeout(() => ghost.element.remove(), SLIDE.duration);
  }
}
