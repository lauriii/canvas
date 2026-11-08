import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
  unionCanvasRects,
} from '@drupal-canvas/preview-geometry';

import { notePreviewEdit } from '@/features/layout/preview/previewEdits';

import type { CanvasBoundary } from '@drupal-canvas/preview-geometry';

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
 * The overlay follows them frame by frame (see useComponentHtmlMap()).
 */
export const LIVE_PREVIEW_ANIMATION = 'canvas-live-preview';

const SLIDE = { duration: 120, easing: 'cubic-bezier(0.2, 0, 0, 1)' };
// The copy of a large component under the pointer shrinks to fit this size,
// down to 40% of its size, so it doesn't hide where it can be dropped.
const GHOST = { width: 480, height: 280, minScale: 0.4 };
// Where the pointer holds the copy of a component from the library.
const GRAB = { x: 12, y: 12 };

interface Point {
  x: number;
  y: number;
}

interface Ghost {
  element: HTMLElement;
  animation: Animation;
  startTime: number;
  frame: number;
}

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
  // Reverts what showing the markup at its current position changed.
  undo: Array<() => void>;
  // The pointer position, in viewport coordinates of the editor.
  pointer?: Point;
  untrack?: () => void;
  // The copy of the dragged markup that follows the pointer.
  ghost?: Ghost;
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

const findBoundary = (boundaries: CanvasBoundary[], type: string, id: string) =>
  boundaries.find((b) => b.type === type && b.id === id);

function siblingsBetween(start: Node, end: Node): Node[] {
  const nodes: Node[] = [];
  for (let n: Node | null = start; n; n = n.nextSibling) {
    nodes.push(n);
    if (n === end) return nodes;
  }
  return [];
}

const isElement = (node: Node): node is HTMLElement =>
  node.nodeType === Node.ELEMENT_NODE;

// Element.animate() is missing in test environments.
const canAnimate = (document: Document) =>
  typeof document.body?.animate === 'function';

// The elements that render boxes, which can be measured and animated.
const boxes = (el: Element): HTMLElement[] =>
  getComputedStyle(el).display === 'contents'
    ? Array.from(el.children).flatMap(boxes)
    : [el as HTMLElement];

// The rectangle of markup.
function bounds(nodes: Node[]): DOMRect {
  const r = unionCanvasRects(
    nodes
      .filter(isElement)
      .flatMap(boxes)
      .map((el) => el.getBoundingClientRect()),
  );
  return r ? new DOMRect(r.left, r.top, r.width, r.height) : new DOMRect();
}

// Maps a point in the editor viewport to the preview viewport.
function toPreview(document: Document, point: Point): Point | null {
  const frame = document.defaultView?.frameElement as HTMLElement | null;
  if (!frame?.offsetWidth) {
    return null;
  }
  const r = frame.getBoundingClientRect();
  const scale = r.width / frame.offsetWidth;
  return { x: (point.x - r.left) / scale, y: (point.y - r.top) / scale };
}

// Outlines empty slots like the overlay does, where the overlay doesn't: in
// inserted markup and in copies.
function outlineEmptySlots(root: ParentNode) {
  root
    .querySelectorAll<HTMLElement>(`.${CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS}`)
    .forEach((el) => {
      el.style.outline = '1px dashed #8e4ec6';
      el.style.outlineOffset = '-5px';
    });
  // Slots of code components are in templates until they hydrate.
  root
    .querySelectorAll('template')
    .forEach((template) => outlineEmptySlots(template.content));
}

// The background a component is shown on.
function backgroundColor(element: Element | null): string {
  for (let el = element; el; el = el.parentElement) {
    const color = getComputedStyle(el).backgroundColor;
    if (!/^(transparent|rgba\(.*,\s*0\))$/.test(color)) {
      return color;
    }
  }
  return '#fff';
}

// Keeps track of the pointer while the session lasts.
function track(current: Session, pointer: Point) {
  current.pointer = pointer;
  const move = (event: PointerEvent) => {
    current.pointer = { x: event.clientX, y: event.clientY };
  };
  window.addEventListener('pointermove', move, true);
  current.untrack = () => window.removeEventListener('pointermove', move, true);
}

/**
 * Lifts a copy of dragged markup under the pointer.
 *
 * The copy is fixed in the preview viewport and moved by an animation, which
 * changes no markup the overlay observes. Being a copy, it keeps the look of
 * the component wherever the component itself is shown.
 *
 * @param grab
 *   Where in the copy the pointer holds it, if it appears there. Without it,
 *   the copy is lifted off the markup where the pointer is.
 */
function liftGhost(current: Session, grab?: Point): Ghost | undefined {
  const { document, nodes } = current;
  const elements = nodes.filter(isElement);
  const origin = current.pointer && toPreview(document, current.pointer);
  const rect = bounds(nodes);
  if (
    !elements.length ||
    !origin ||
    !canAnimate(document) ||
    !(rect.width && rect.height)
  ) {
    return undefined;
  }
  const at = grab
    ? { x: origin.x - grab.x, y: origin.y - grab.y }
    : { x: rect.left, y: rect.top };
  const element = document.createElement('div');
  element.setAttribute('aria-hidden', 'true');
  element.inert = true;
  elements.forEach((el) => {
    const copy = el.cloneNode(true) as HTMLElement;
    // Keep the copy out of what the overlay maps to components.
    copy.removeAttribute('data-canvas-uuid');
    copy
      .querySelectorAll('[data-canvas-uuid]')
      .forEach((c) => c.removeAttribute('data-canvas-uuid'));
    copy.querySelectorAll('template[data-canvas-marker]').forEach((c) => {
      c.remove();
    });
    const walker = document.createTreeWalker(copy, NodeFilter.SHOW_COMMENT);
    const markers: Node[] = [];
    while (walker.nextNode()) {
      if (walker.currentNode.nodeValue?.trim().startsWith('canvas-')) {
        markers.push(walker.currentNode);
      }
    }
    markers.forEach((marker) => marker.parentNode?.removeChild(marker));
    outlineEmptySlots(copy);
    element.append(copy);
  });
  const parent = elements[0].parentElement ?? document.body;
  const context = getComputedStyle(parent);
  const scale = Math.min(
    1,
    Math.max(
      GHOST.minScale,
      Math.min(GHOST.width / rect.width, GHOST.height / rect.height),
    ),
  );
  Object.assign(element.style, {
    position: 'fixed',
    left: `${at.x}px`,
    top: `${at.y}px`,
    width: `${rect.width}px`,
    margin: '0',
    zIndex: '2147483647',
    pointerEvents: 'none',
    overflow: 'hidden',
    borderRadius: '6px',
    color: context.color,
    fontFamily: context.fontFamily,
    fontSize: context.fontSize,
    lineHeight: context.lineHeight,
    background: backgroundColor(parent),
    boxShadow: '0 4px 14px rgb(0 0 0 / 0.12), 0 0 0 1px rgb(110 86 207 / 0.5)',
    transformOrigin: `${origin.x - at.x}px ${origin.y - at.y}px`,
  });
  document.body.append(element);
  const ghost: Ghost = {
    element,
    animation: element.animate([{ translate: '0 0', scale: '1' }], {
      duration: 0,
      fill: 'forwards',
    }),
    startTime: performance.now(),
    frame: 0,
  };
  // A lifted copy shrinks to size; one that appears fades in at size.
  const from = grab ? { scale, opacity: 0 } : { scale: 1, opacity: 1 };
  const follow = (time: number) => {
    const p = current.pointer && toPreview(document, current.pointer);
    if (p) {
      const t = Math.min(1, Math.max(0, time - ghost.startTime) / 100);
      const eased = 1 - (1 - t) ** 3;
      (ghost.animation.effect as KeyframeEffect).setKeyframes([
        {
          translate: `${p.x - origin.x}px ${p.y - origin.y}px`,
          scale: String(from.scale + (scale - from.scale) * eased),
          opacity: String(from.opacity + (0.96 - from.opacity) * eased),
        },
      ]);
    }
    ghost.frame = requestAnimationFrame(follow);
  };
  ghost.frame = requestAnimationFrame(follow);
  return ghost;
}

/**
 * Settles the copy of dragged markup onto the markup, fading it out.
 *
 * Without markup shown, e.g. a canceled insert, it fades out where it is.
 */
function dropGhost(ghost: Ghost, onto: Node[]) {
  cancelAnimationFrame(ghost.frame);
  const { element } = ghost;
  const target = bounds(onto);
  const from = (ghost.animation.effect as KeyframeEffect).getKeyframes()[0];
  const left = parseFloat(element.style.left);
  const top = parseFloat(element.style.top);
  const shown = target.width && target.height;
  element.animate(
    [
      { translate: from.translate, scale: from.scale, opacity: from.opacity },
      {
        translate: shown
          ? `${target.left - left}px ${target.top - top}px`
          : from.translate,
        scale: shown ? '1' : from.scale,
        opacity: '0',
      },
    ],
    { ...SLIDE, fill: 'forwards' },
  );
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
 */
function slide(before: Map<HTMLElement, DOMRect>, animations: Animation[]) {
  const moved = new Map<HTMLElement, Point>();
  before.forEach((was, el) => {
    if (!el.isConnected) {
      return;
    }
    const is = el.getBoundingClientRect();
    moved.set(el, { x: was.left - is.left, y: was.top - is.top });
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
        ...SLIDE,
        id: LIVE_PREVIEW_ANIMATION,
      }),
    );
  });
}

function isComponentStart(node: Node): boolean {
  if (node.nodeType === Node.COMMENT_NODE) {
    return node.nodeValue?.trim().startsWith('canvas-start-') ?? false;
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
    undo: [],
    dim: [],
    slide: [],
  };
  if (pointer && canAnimate(document)) {
    track(session, pointer);
    lift(session);
  }
}

// Lifts a copy of the dragged markup under the pointer, dimming the markup.
function lift(current: Session, grab?: Point) {
  current.ghost = liftGhost(current, grab);
  if (current.ghost) {
    current.dim = current.nodes
      .filter(isElement)
      .flatMap(boxes)
      .map((el) =>
        el.animate([{ opacity: '0.4' }], { ...SLIDE, fill: 'forwards' }),
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
    track(session, pointer);
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

function hidePlaceholders(container: CanvasBoundary, undo: Session['undo']) {
  siblingsBetween(container.start, container.end).forEach((node) => {
    if (
      node.nodeType === Node.ELEMENT_NODE &&
      (node as HTMLElement).matches(
        `.${CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS}, .${CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS}`,
      )
    ) {
      const el = node as HTMLElement;
      const display = el.style.display;
      el.style.display = 'none';
      undo.push(() => {
        el.style.display = display;
      });
    }
  });
}

/**
 * Shows the dragged component at a drop position.
 *
 * @param dropZoneId
 *   The drop zone id of the position, or NULL to show the component where it
 *   was when the drag started (or not at all if it is new).
 */
export function showLivePreviewAt(dropZoneId: string | null): void {
  if (!session) {
    return;
  }
  const { document, nodes } = session;
  // Where components are shown, mid-slide or not, to slide them from there.
  const before = new Map<HTMLElement, DOMRect>();
  if (canAnimate(document)) {
    componentElements(discoverCanvasBoundaries(document)).forEach((el) =>
      before.set(el, el.getBoundingClientRect()),
    );
    nodes
      .filter(isElement)
      .flatMap(boxes)
      .forEach((el) => before.set(el, el.getBoundingClientRect()));
  }
  session.slide.forEach((animation) => animation.cancel());
  session.slide = [];
  try {
    place(session, dropZoneId);
  } finally {
    slide(before, session.slide);
  }
  // The copy of inserted markup appears once the markup can be measured.
  if (!session.home && !session.ghost && session.pointer) {
    lift(session, GRAB);
  }
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
    nodes.forEach((node) => target.parent.insertBefore(node, target.before));
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
function release({ ghost, dim, nodes, untrack }: Session) {
  untrack?.();
  dim.forEach((animation) => animation.reverse());
  if (ghost) {
    dropGhost(ghost, nodes);
    // Not on the animation finishing: that never happens in a hidden tab.
    setTimeout(() => ghost.element.remove(), SLIDE.duration);
  }
}
