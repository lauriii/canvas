import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
} from '@drupal-canvas/preview-geometry';

import { NodeType } from '@/features/layout/layoutModelSlice';
import { findParent } from '@/features/layout/layoutUtils';
import { notePreviewEdit } from '@/features/layout/preview/previewEdits';

import type { CanvasBoundary } from '@drupal-canvas/preview-geometry';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

// Dropping a component otherwise shows nothing until the server has rendered a
// new preview. Updating the current preview's markup lets the drop land
// immediately. The server-rendered preview is then shown in place: only the
// instances whose markup differs are replaced (see updateInPlace in
// inPlaceUpdate.ts). The whole document is loaded when it doesn't have the
// components where that preview has them.

/**
 * The id of the styles kept last in the head of the preview.
 *
 * @see IframeSwapper
 */
export const PREVIEW_HEAD_STYLES_ID = 'canvas-preview-head-styles';

/**
 * Marks the CSS added to the head of the preview ahead of the server.
 *
 * @see addPreviewCss
 */
export const OPTIMISTIC_CSS_ATTRIBUTE = 'data-canvas-optimistic';

/** Finds the boundary of a component, slot or region by its id. */
export const findBoundary = (
  boundaries: CanvasBoundary[],
  type: string,
  id: string,
) => boundaries.find((b) => b.type === type && b.id === id);

/**
 * Returns the nodes from one node to another sibling, both included.
 *
 * Empty if the second node doesn't follow the first one.
 */
export function siblingsBetween(start: Node, end: Node): Node[] {
  const nodes: Node[] = [];
  for (let n: Node | null = start; n; n = n.nextSibling) {
    nodes.push(n);
    if (n === end) return nodes;
  }
  return [];
}

/**
 * Hides the placeholder of an empty slot or region.
 *
 * @param container
 *   The slot or region.
 * @param undo
 *   Collects functions that show the placeholder again.
 */
export function hidePlaceholders(
  container: CanvasBoundary,
  undo: Array<() => void> = [],
) {
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
 * Returns the document of the preview iframe that is currently shown.
 *
 * NULL for a headless (cross-origin) preview.
 */
export const getPreviewDocument = (): Document | null =>
  document.querySelector<HTMLIFrameElement>(
    'iframe[data-canvas-swap-active="true"]',
  )?.contentDocument ?? null;

/**
 * Finds where the markup of a component belongs given its place in the layout.
 */
function findPosition(
  document: Document,
  layout: RegionNode[],
  uuid: string,
): {
  anchor: Node & { parentNode: ParentNode };
  container?: CanvasBoundary;
  find: (type: string, id: string) => CanvasBoundary | undefined;
} | null {
  const parent = findParent(layout, uuid);
  if (!parent || !('components' in parent)) {
    return null;
  }
  const boundaries = discoverCanvasBoundaries(document);
  const find = (type: string, id: string) => findBoundary(boundaries, type, id);
  const next =
    parent.components[parent.components.findIndex((c) => c.uuid === uuid) + 1];
  const container = find(
    parent.nodeType === NodeType.Region ? 'region' : 'slot',
    parent.id,
  );
  const anchor = next ? find('component', next.uuid)?.start : container?.end;
  return anchor?.parentNode
    ? { anchor: anchor as Node & { parentNode: ParentNode }, container, find }
    : null;
}

function insertBefore(
  anchor: Node & { parentNode: ParentNode },
  node: Node,
  container?: CanvasBoundary,
) {
  anchor.parentNode.insertBefore(node, anchor);
  // The container is no longer empty.
  // ponytail: the container a moved component leaves isn't given a placeholder
  // when it becomes empty; it is added when the server-rendered preview is
  // shown, shortly after (see updateInPlace in inPlaceUpdate.ts).
  if (container) {
    hidePlaceholders(container);
  }
}

/**
 * Moves a component's markup in the preview to match its place in the layout.
 *
 * @param document
 *   The preview document.
 * @param layout
 *   The layout in which the component already is at its new position.
 * @param uuid
 *   The uuid of the moved component.
 *
 * @return
 *   Whether the markup was moved. It is not when the markers of the component
 *   or of its new position can't be found.
 */
export function movePreviewComponent(
  document: Document,
  layout: RegionNode[],
  uuid: string,
): boolean {
  const position = findPosition(document, layout, uuid);
  const moved = position?.find('component', uuid);
  if (!position || !moved || moved.start.parentNode !== moved.end.parentNode) {
    return false;
  }
  // E.g. the live preview shows it there already.
  let next = moved.end.nextSibling;
  while (next?.nodeType === Node.TEXT_NODE && !next.textContent?.trim()) {
    next = next.nextSibling;
  }
  if (next === position.anchor) {
    return true;
  }
  const range = document.createRange();
  range.setStartBefore(moved.start);
  range.setEndAfter(moved.end);
  insertBefore(position.anchor, range.extractContents(), position.container);
  notePreviewEdit(document);
  return true;
}

/**
 * Inserts a new component's default markup in the preview.
 *
 * @param document
 *   The preview document.
 * @param layout
 *   The layout the component has been added to.
 * @param uuid
 *   The uuid of the added component.
 * @param markup
 *   The default markup and CSS of the component.
 *
 * @return
 *   Whether the markup was inserted. It is not when the markers of its
 *   position can't be found.
 */
export function insertPreviewComponent(
  document: Document,
  layout: RegionNode[],
  uuid: string,
  markup: { default_markup: string; css: string },
): boolean {
  const position = findPosition(document, layout, uuid);
  if (!position) {
    return false;
  }
  addPreviewCss(document, markup.css);
  const marker = (position: 'start' | 'end', id: string) =>
    `<!-- ${formatCanvasCommentMarker({ type: 'component', position, id })} -->`;
  // The default markup is rendered with a uuid of its own, in its markers and
  // e.g. on its Canvas island. Give it the uuid of this instance, as the
  // server-rendered preview has it, so that one finds this markup to be its
  // own (see inPlaceUpdate.ts).
  const [, defaultUuid] =
    markup.default_markup.match(
      new RegExp(`^\\s*${marker('start', '(.+?)')}`),
    ) ?? [];
  // Template content is inert: scripts in it don't run.
  const template = document.createElement('template');
  template.innerHTML = defaultUuid
    ? markup.default_markup.replaceAll(defaultUuid, uuid)
    : marker('start', uuid) + markup.default_markup + marker('end', uuid);
  insertBefore(position.anchor, template.content, position.container);
  notePreviewEdit(document);
  return true;
}

/**
 * Adds the CSS of a component to the preview, unless it is there already.
 *
 * @param document
 *   The preview document.
 * @param css
 *   The <link> and <style> elements of the component.
 */
export function addPreviewCss(document: Document, css: string) {
  const template = document.createElement('template');
  template.innerHTML = css;
  template.content.querySelectorAll('link, style').forEach((el) => {
    const present = Array.from(
      document.head.querySelectorAll(el.localName),
    ).some((other) =>
      el.localName === 'link'
        ? other.getAttribute('href') === el.getAttribute('href')
        : other.textContent === el.textContent,
    );
    // The head, which is where the server-rendered preview has it too, before
    // the styles that are kept last. Marked, as that preview may turn out not
    // to have it: it is removed once that one is shown (see inPlaceUpdate.ts).
    if (!present) {
      el.setAttribute(OPTIMISTIC_CSS_ATTRIBUTE, '');
      document.head.insertBefore(
        el,
        document.getElementById(PREVIEW_HEAD_STYLES_ID),
      );
    }
  });
}

/**
 * Removes a component's markup from the preview.
 *
 * @param document
 *   The preview document.
 * @param uuid
 *   The uuid of the removed component.
 */
export function removePreviewComponent(document: Document, uuid: string) {
  const removed = findBoundary(
    discoverCanvasBoundaries(document),
    'component',
    uuid,
  );
  if (removed && removed.start.parentNode === removed.end.parentNode) {
    const range = document.createRange();
    range.setStartBefore(removed.start);
    range.setEndAfter(removed.end);
    range.deleteContents();
    notePreviewEdit(document);
  }
}
