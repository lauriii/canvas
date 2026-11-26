import { discoverCanvasBoundaries } from '@drupal-canvas/preview-geometry';

import { siblingsBetween } from '@/features/layout/preview/optimisticPreview';

import type { PageVersionChange } from '@/features/versionComparison/pageVersionDiff';

export type HighlightKind = 'added' | 'removed' | 'changed';

/** Component highlights per version, keyed by component uuid. */
export type VersionHighlights = Record<
  'published' | 'new',
  Map<string, HighlightKind>
>;

const HIGHLIGHT_ATTRIBUTE = 'data-canvas-version-diff';
const HIGHLIGHT_STYLE_ID = 'canvas-version-diff-highlights';
const HIGHLIGHT_CSS = `
[${HIGHLIGHT_ATTRIBUTE}] { outline-offset: -2px !important; }
[${HIGHLIGHT_ATTRIBUTE}="added"] { outline: 2px solid #30a46c !important; }
[${HIGHLIGHT_ATTRIBUTE}="removed"] { outline: 2px solid #e5484d !important; }
[${HIGHLIGHT_ATTRIBUTE}="changed"] { outline: 2px solid #ffb224 !important; }
`;

/**
 * Maps component changes to the version previews they show in.
 */
export const getVersionHighlights = (
  changes: PageVersionChange[],
): VersionHighlights => {
  const highlights: VersionHighlights = {
    published: new Map(),
    new: new Map(),
  };
  changes.forEach((change) => {
    if (change.kind === 'field') return;
    const uuid = change.node.uuid;
    if (change.kind === 'added') {
      highlights.new.set(uuid, 'added');
    } else if (change.kind === 'removed') {
      highlights.published.set(uuid, 'removed');
    } else {
      highlights.published.set(uuid, 'changed');
      highlights.new.set(uuid, 'changed');
    }
  });
  return highlights;
};

// Wrappers such as `canvas-island` use `display: contents` and draw no
// outline, so mark the elements they contain instead.
const markElement = (element: Element, kind: HighlightKind) => {
  const view = element.ownerDocument.defaultView;
  if (view?.getComputedStyle(element).display === 'contents') {
    [...element.children].forEach((child) => markElement(child, kind));
    return;
  }
  element.setAttribute(HIGHLIGHT_ATTRIBUTE, kind);
};

/**
 * Outlines the elements of highlighted components in a preview document.
 *
 * Replaces highlights from an earlier call. An empty map removes them all.
 */
export const applyVersionHighlights = (
  document: Document,
  highlights: Map<string, HighlightKind>,
) => {
  document
    .querySelectorAll(`[${HIGHLIGHT_ATTRIBUTE}]`)
    .forEach((element) => element.removeAttribute(HIGHLIGHT_ATTRIBUTE));

  if (!document.getElementById(HIGHLIGHT_STYLE_ID)) {
    const style = document.createElement('style');
    style.id = HIGHLIGHT_STYLE_ID;
    style.textContent = HIGHLIGHT_CSS;
    document.head?.append(style);
  }

  if (!highlights.size) return;
  discoverCanvasBoundaries(document).forEach((boundary) => {
    const kind =
      boundary.type === 'component' ? highlights.get(boundary.id) : undefined;
    if (!kind) return;
    siblingsBetween(boundary.start, boundary.end).forEach((node) => {
      // Not `instanceof Element`: preview nodes belong to the iframe's realm.
      if (node.nodeType === Node.ELEMENT_NODE) {
        markElement(node as Element, kind);
      }
    });
  });
};
