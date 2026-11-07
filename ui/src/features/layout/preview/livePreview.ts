import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
} from '@drupal-canvas/preview-geometry';

import type { CanvasBoundary } from '@drupal-canvas/preview-geometry';

/**
 * Shows the dragged component at its drop position while it is dragged.
 *
 * Moving a component moves its markup in the preview; dragging one from the
 * library inserts its default markup. The page reflows around it, so what you
 * see is what you get when you let go. A drop commits the preview; otherwise
 * ending it puts the markup back where it was.
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
}

let session: Session | null = null;

/** Whether the dragged component is shown at its drop position. */
export const isLivePreviewActive = () => session !== null;

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
 */
export function startMovePreview(
  document: Document,
  uuid: string,
  homeContainer: { type: 'slot' | 'region'; id: string },
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
  };
}

/**
 * Starts showing a component from the library at its drop position.
 *
 * @param document
 *   The preview document.
 * @param markup
 *   The default markup of the component. A labeled box is shown without it.
 * @param name
 *   The name of the component.
 */
export function startInsertPreview(
  document: Document,
  markup: string | undefined,
  name: string,
): void {
  endLivePreview();
  const template = document.createElement('template');
  if (markup) {
    // Template content is inert: scripts in it don't run.
    template.innerHTML = markup;
  } else {
    const box = document.createElement('div');
    box.textContent = name;
    box.style.cssText =
      'display:flex;align-items:center;justify-content:center;min-height:48px;font:500 13px/1 system-ui,sans-serif;color:#6e56cf;background:#f5f2ff;border-radius:4px;';
    template.content.append(box);
  }
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
  session = { document, nodes, home: null, undo: [] };
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
  const { document, nodes, home, homeContainer } = session;
  session.undo.forEach((revert) => revert());
  session.undo = [];

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
  if (target.container) {
    hidePlaceholders(target.container, session.undo);
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
    session.undo.push(() => placeholder.remove());
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
 *   The preview document, or NULL if there was no live preview.
 */
export function commitLivePreview(uuid?: string): Document | null {
  if (!session) {
    return null;
  }
  const { document, nodes, home } = session;
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
  session = null;
  return document;
}

/** Stops the live preview, putting the dragged markup back where it was. */
export function endLivePreview(): void {
  if (!session) {
    return;
  }
  showLivePreviewAt(null);
  session = null;
}
