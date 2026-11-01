import {
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
} from '@drupal-canvas/preview-geometry';

/**
 * The new markup of the one component instance that changed between renders.
 */
export interface InPlaceUpdate {
  componentUuid: string;
  html: string;
  previousHtml: string;
}

const commentMarker = (position: 'start' | 'end', id: string) =>
  `<!-- ${formatCanvasCommentMarker({ type: 'component', position, id })} -->`;

const START_MARKER_PREFIX = commentMarker('start', '').slice(0, -' -->'.length);

/**
 * Finds the innermost component instance enclosing every difference between
 * two preview documents.
 *
 * Returns null when any difference lies outside a component instance (for
 * example new assets in <head>, or changed drupalSettings), or when nothing
 * changed. The caller must then load the whole document.
 */
export function findInPlaceUpdate(
  previous: string,
  next: string,
): InPlaceUpdate | null {
  if (previous === next) {
    return null;
  }
  const shortest = Math.min(previous.length, next.length);
  let prefix = 0;
  while (prefix < shortest && previous[prefix] === next[prefix]) {
    prefix++;
  }
  let suffix = 0;
  while (
    suffix < shortest - prefix &&
    previous[previous.length - 1 - suffix] === next[next.length - 1 - suffix]
  ) {
    suffix++;
  }
  const changeEnd = previous.length - suffix;

  // The nearest start marker before the change whose end marker comes after
  // it is the innermost instance containing the change. Skipped candidates
  // are earlier siblings, which close before the change.
  let at = previous.lastIndexOf(START_MARKER_PREFIX, prefix);
  while (at !== -1) {
    const close = previous.indexOf(' -->', at);
    const contentStart = close + ' -->'.length;
    if (close !== -1 && contentStart <= prefix) {
      const id = previous.slice(at + START_MARKER_PREFIX.length, close);
      const end = previous.indexOf(commentMarker('end', id), contentStart);
      if (end >= changeEnd) {
        return {
          componentUuid: id,
          html: next.slice(contentStart, end - previous.length + next.length),
          previousHtml: previous.slice(contentStart, end),
        };
      }
    }
    at = at > 0 ? previous.lastIndexOf(START_MARKER_PREFIX, at - 1) : -1;
  }
  return null;
}

/**
 * Replaces a component instance's markup, including its markers, in a preview
 * document. Returns null if the document lacks the instance.
 */
export function spliceComponentHtml(
  document: string,
  { uuid, html }: { uuid: string; html: string },
): string | null {
  const start = document.indexOf(commentMarker('start', uuid));
  const endMarker = commentMarker('end', uuid);
  const end = document.indexOf(endMarker, start);
  if (start === -1 || end === -1) {
    return null;
  }
  return (
    document.slice(0, start) + html + document.slice(end + endMarker.length)
  );
}

const parseFragment = (document: Document, html: string) => {
  const template = document.createElement('template');
  template.innerHTML = html;
  return template;
};

/**
 * Updates the props of a live Canvas island when they are all that changed.
 * Replacing the island instead would render it empty until it hydrates again.
 */
function updateIslandProps(
  document: Document,
  live: Node[],
  previousHtml: string,
  html: string,
): boolean {
  const liveElements = live.filter(
    (node): node is Element => node.nodeType === Node.ELEMENT_NODE,
  );
  const [island] = liveElements;
  const fragments = [previousHtml, html].map((markup) =>
    parseFragment(document, markup),
  );
  const [before, after] = fragments.map(({ content }) =>
    content.children.length === 1 &&
    content.firstElementChild?.localName === 'canvas-island'
      ? content.firstElementChild
      : null,
  );
  const props = after?.getAttribute('props');
  if (
    liveElements.length !== 1 ||
    island.localName !== 'canvas-island' ||
    !before ||
    !after ||
    props == null
  ) {
    return false;
  }
  // Everything else, including text and comments beside the island, must be
  // unchanged.
  before.removeAttribute('props');
  after.removeAttribute('props');
  if (fragments[0].innerHTML !== fragments[1].innerHTML) {
    return false;
  }
  island.setAttribute('props', props);
  return true;
}

/**
 * Replaces a component instance's markup in a live preview document.
 *
 * A Canvas island whose props alone changed keeps its element. Otherwise
 * this detaches Drupal behaviors from the old markup and attaches them to the
 * markers' parent element, which contains the new markup. Inserted scripts
 * run and Canvas islands hydrate on insertion, as on a full load. Returns
 * false when the instance's markers are not siblings in the document, so the
 * caller must load the whole document instead.
 */
export function applyInPlaceUpdate(
  document: Document,
  { componentUuid, html, previousHtml }: InPlaceUpdate,
): boolean {
  const boundary = discoverCanvasBoundaries(document, {
    templateMarkers: false,
  }).find(({ type, id }) => type === 'component' && id === componentUuid);
  const parent = boundary?.start.parentNode;
  if (!boundary || !parent || parent !== boundary.end.parentNode) {
    return false;
  }
  const Drupal = (document.defaultView as (Window & { Drupal?: any }) | null)
    ?.Drupal;

  const range = document.createRange();
  range.setStartAfter(boundary.start);
  range.setEndBefore(boundary.end);
  const removed: Node[] = [];
  for (
    let node = boundary.start.nextSibling;
    node && node !== boundary.end;
    node = node.nextSibling
  ) {
    removed.push(node);
  }
  if (updateIslandProps(document, removed, previousHtml, html)) {
    return true;
  }
  removed
    .filter((node) => node.nodeType === Node.ELEMENT_NODE)
    .forEach((element) => Drupal?.detachBehaviors(element, null, 'unload'));

  const fragment = range.createContextualFragment(html);
  range.deleteContents();
  parent.insertBefore(fragment, boundary.end);
  // Behaviors find their targets below the context, so the context must
  // contain the new markup's root elements. once() keeps unchanged siblings
  // from being initialized twice.
  if (parent.nodeType === Node.ELEMENT_NODE) {
    Drupal?.attachBehaviors(parent);
  }
  return true;
}
