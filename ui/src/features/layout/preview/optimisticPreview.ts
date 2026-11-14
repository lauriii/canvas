import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
  parseCanvasCommentMarker,
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
 * Shows the placeholder of a slot or region if it has nothing in it, as the
 * server renders it, and removes it otherwise.
 */
function syncEmptyPlaceholder({ type, start, end }: CanvasBoundary) {
  if (start.parentNode !== end.parentNode) {
    return;
  }
  const className =
    type === 'region'
      ? CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS
      : CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS;
  let empty = true;
  let placeholder: HTMLElement | null = null;
  for (let node = start.nextSibling; node && node !== end;) {
    if ((node as Element).classList?.contains(className)) {
      placeholder = node as HTMLElement;
    } else if (
      node.nodeType === Node.ELEMENT_NODE ||
      (node.nodeType === Node.COMMENT_NODE &&
        parseCanvasCommentMarker(node.nodeValue ?? ''))
    ) {
      // A component that failed to render has no markers.
      empty = false;
    }
    node = node.nextSibling;
  }
  if (!empty) {
    placeholder?.remove();
  } else if (placeholder) {
    placeholder.style.display = '';
  } else {
    placeholder = end.ownerDocument!.createElement('div');
    placeholder.className = className;
    end.parentNode!.insertBefore(placeholder, end);
  }
}

/**
 * Shows the placeholder of each slot and region in the preview that has
 * nothing in it, as the server renders it, and removes the others.
 *
 * @param document
 *   The preview document.
 */
export function syncPlaceholders(document: Document) {
  discoverCanvasBoundaries(document, { templateMarkers: false })
    .filter(({ type }) => type !== 'component')
    .forEach(syncEmptyPlaceholder);
}

// Resolves once each Canvas island in a list has hydrated, or failed to, but
// within a second, as a preview swap waits for them (see IframeSwapper).
const islandsHydrated = (islands: Element[]) =>
  Promise.race([
    Promise.all(
      islands
        .filter((island) => island.hasAttribute('ssr'))
        .map(
          (island) =>
            new Promise((resolve) => {
              island.addEventListener('astro:hydrate', resolve, { once: true });
              island.addEventListener('astro:hydration-error', resolve, {
                once: true,
              });
            }),
        ),
    ),
    new Promise((resolve) => setTimeout(resolve, 1000)),
  ]);

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
  const anchor = next
    ? find('component', next.uuid)?.start
    : find(parent.nodeType === NodeType.Region ? 'region' : 'slot', parent.id)
        ?.end;
  return anchor?.parentNode
    ? { anchor: anchor as Node & { parentNode: ParentNode }, find }
    : null;
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
  position.anchor.parentNode.insertBefore(
    range.extractContents(),
    position.anchor,
  );
  syncPlaceholders(document);
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
  const islands = Array.from(
    template.content.querySelectorAll('canvas-island'),
  );
  position.anchor.parentNode.insertBefore(template.content, position.anchor);
  // A Canvas island is empty until it has hydrated. The placeholder of the
  // slot it was added to keeps its height until then.
  if (islands.length) {
    islandsHydrated(islands).then(() => syncPlaceholders(document));
  } else {
    syncPlaceholders(document);
  }
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
    syncPlaceholders(document);
    notePreviewEdit(document);
  }
}
