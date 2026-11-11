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
// immediately; the server-rendered preview then replaces the whole document,
// correcting anything this approximates.

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
  const find = (type: string, id: string) =>
    boundaries.find((b) => b.type === type && b.id === id);
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
  // when it becomes empty; the server-rendered preview adds it shortly after.
  for (
    let n = container?.start.nextSibling;
    n && n !== container?.end;
    n = n.nextSibling
  ) {
    if (
      n.nodeType === Node.ELEMENT_NODE &&
      (n as Element).matches(
        `.${CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS}, .${CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS}`,
      )
    ) {
      n.parentNode!.removeChild(n);
      break;
    }
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
  const range = document.createRange();
  range.setStartBefore(moved.start);
  range.setEndAfter(moved.end);
  insertBefore(position.anchor, range.extractContents(), position.container);
  notePreviewEdit();
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
  // Template content is inert: scripts in it don't run.
  const template = document.createElement('template');
  template.innerHTML = `<!--${formatCanvasCommentMarker({ type: 'component', position: 'start', id: uuid })}-->${markup.default_markup}<!--${formatCanvasCommentMarker({ type: 'component', position: 'end', id: uuid })}-->`;
  insertBefore(position.anchor, template.content, position.container);
  notePreviewEdit();
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
    // The head, which is where the server-rendered preview has it too.
    if (!present) {
      document.head.appendChild(el);
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
  const removed = discoverCanvasBoundaries(document).find(
    (b) => b.type === 'component' && b.id === uuid,
  );
  if (removed && removed.start.parentNode === removed.end.parentNode) {
    const range = document.createRange();
    range.setStartBefore(removed.start);
    range.setEndAfter(removed.end);
    range.deleteContents();
    notePreviewEdit();
  }
}
