import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  discoverCanvasBoundaries,
  formatCanvasCommentMarker,
  parseCanvasCommentMarker,
} from '@drupal-canvas/preview-geometry';

import {
  OPTIMISTIC_CSS_ATTRIBUTE,
  syncPlaceholders,
} from '@/features/layout/preview/optimisticPreview';
import {
  isPreviewLayoutEdited,
  notePreviewRendered,
} from '@/features/layout/preview/previewEdits';

/**
 * The new markup of a component instance that changed between renders.
 */
export interface InPlaceUpdate {
  componentUuid: string;
  html: string;
  // Not set for an instance the previous render doesn't have.
  previousHtml?: string;
}

const marker = (position: 'start' | 'end', id: string) =>
  formatCanvasCommentMarker({ type: 'component', position, id });

const COMPONENT_MARKER = new RegExp(
  `<!-- (${marker('start', '')}|${marker('end', '')})(.+?) -->`,
  'g',
);

// The markers of components, slots and regions, which tell where each is, and
// the element printed instead of a component that failed to render, which
// has no markers.
// @see \Drupal\canvas\Element\RenderSafeComponentContainer::handleComponentException()
const MARKER =
  /<!-- canvas-(?:slot-|region-)?(?:start|end)-.+? -->|<div [^>]*?\bdata-component-uuid="([^"]*)"/g;
const failedMarker = (uuid: string) => `<failed ${uuid}>`;

// Where the components, slots and regions of a render are.
const markersOf = (html: string) =>
  Array.from(html.matchAll(MARKER), ([text, failed]) =>
    failed === undefined ? text : failedMarker(failed),
  ).join('');

// What the server renders in a slot or region with no components. Whether it
// is there follows from where the components are, so it isn't a change to the
// markup of the instance or document around it.
const EMPTY_PLACEHOLDER = new RegExp(
  `<div class="(${CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS}|${CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS})"></div>`,
  'g',
);

const MODULE_PRELOAD = /<link rel="modulepreload"[^>]*>/g;

// An empty slot of a Canvas island. The island renders its slots itself, and
// may render differently when one is empty.
const EMPTY_ISLAND_SLOT = new RegExp(
  `<template data-astro-template="[^"]*"><!-- canvas-slot-start-[^>]*? --><div class="${CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS}"></div>`,
  'g',
);

interface Instance {
  id: string;
  parent: string;
  // The markup between the markers of the instance.
  html: string;
  // That markup without the instances in it.
  own: string;
}

/**
 * Splits a preview document into its component instances, in document order.
 *
 * The first one stands for the document itself: its own markup is all that is
 * outside any instance. Null when the markers aren't nested properly, or an
 * instance is there twice (e.g. in a slot that a component prints twice).
 */
function splitInstances(html: string): Instance[] | null {
  const instances = [{ id: '', parent: '', html, own: '', start: 0 }];
  const open = [instances[0]];
  const ids = new Set<string>();
  let at = 0;
  for (const match of html.matchAll(COMPONENT_MARKER)) {
    const [text, prefix, id] = match;
    const top = open[open.length - 1];
    top.own += html.slice(at, match.index);
    at = match.index + text.length;
    if (prefix === marker('start', '')) {
      if (ids.has(id)) {
        return null;
      }
      ids.add(id);
      const instance = { id, parent: top.id, html: '', own: '', start: at };
      instances.push(instance);
      open.push(instance);
    } else if (top.id !== id) {
      return null;
    } else {
      top.html = html.slice(top.start, match.index);
      open.pop();
    }
  }
  if (open.length !== 1) {
    return null;
  }
  instances[0].own += html.slice(at);
  return instances;
}

/**
 * Finds the component instances whose markup differs between two preview
 * documents.
 *
 * An instance whose own markup changed comes with the instances in it. One
 * that only has different instances in it, or is in a different place, isn't
 * listed: where instances are is not compared here.
 *
 * Returns null when anything outside the component instances changed (for
 * example new assets in <head>, or changed drupalSettings), or a slot of a
 * Canvas island became empty or got content: the island must then render
 * again, and would show empty until it has. The caller must then load the
 * whole document.
 */
export function findInPlaceUpdates(
  previous: string,
  next: string,
): InPlaceUpdate[] | null {
  const before = splitInstances(previous);
  const after = splitInstances(next);
  const own = (instance?: Instance) =>
    instance?.own.replace(EMPTY_PLACEHOLDER, '');
  // The links that preload the modules of code components are in the order
  // the components are rendered in, which has no effect on the document.
  const outside = ([document]: Instance[]) =>
    own(document)!.replace(MODULE_PRELOAD, '') +
    (document.own.match(MODULE_PRELOAD) ?? []).sort().join('');
  if (!before || !after || outside(before) !== outside(after)) {
    return null;
  }
  const previousInstances = new Map(before.map((i) => [i.id, i]));
  const emptyIslandSlots = (instance?: Instance) =>
    instance?.own.match(EMPTY_ISLAND_SLOT)?.join('');
  if (
    after.some((instance) => {
      const was = previousInstances.get(instance.id);
      return was && emptyIslandSlots(was) !== emptyIslandSlots(instance);
    })
  ) {
    return null;
  }
  const replaced = new Set<string>();
  return after.slice(1).flatMap((instance) => {
    const { id, parent, html } = instance;
    const was = previousInstances.get(id);
    // New markup of an instance has the instances in it.
    if (replaced.has(parent)) {
      replaced.add(id);
      return [];
    }
    if (own(was) === own(instance)) {
      return [];
    }
    replaced.add(id);
    return [{ componentUuid: id, html, previousHtml: was?.html }];
  });
}

// Decodes a Canvas island's props attribute, whose values are JSON-encoded
// individually, so equal props compare equal however they were encoded.
const decodeIslandProps = (props: string | null) => {
  try {
    const decoded = Object.entries(JSON.parse(props ?? '{}')).map(
      ([name, value]) => [
        name,
        typeof value === 'string' ? JSON.parse(value) : value,
      ],
    );
    decoded.sort(([a], [b]) => (a < b ? -1 : 1));
    return JSON.stringify(decoded);
  } catch {
    return props;
  }
};

const parseFragment = (document: Document, html: string) => {
  const template = document.createElement('template');
  template.innerHTML = html;
  return template;
};

// The Canvas island a component instance's markup consists of, if it does.
const soleIsland = ({ content }: HTMLTemplateElement) =>
  content.children.length === 1 &&
  content.firstElementChild?.localName === 'canvas-island'
    ? content.firstElementChild
    : null;

/**
 * Updates the props of a live Canvas island when they are all that changed.
 * Replacing the island instead would render it empty until it hydrates again.
 */
function updateIslandProps(
  document: Document,
  live: Node[],
  previousHtml: string | undefined,
  html: string,
): boolean {
  const liveElements = live.filter(
    (node): node is Element => node.nodeType === Node.ELEMENT_NODE,
  );
  const [island] = liveElements;
  const next = parseFragment(document, html);
  const after = soleIsland(next);
  const props = after?.getAttribute('props');
  if (
    liveElements.length !== 1 ||
    island.localName !== 'canvas-island' ||
    !after ||
    props == null
  ) {
    return false;
  }
  if (previousHtml === undefined) {
    // An instance the previous render doesn't have is shown ahead of the
    // server rendering it (see optimisticPreview.ts). Its island stays if it
    // has all that the rendered one has.
    if (
      !Array.from(after.attributes).every(
        ({ name, value }) =>
          name === 'props' ||
          name === 'ssr' ||
          island.getAttribute(name) === value,
      )
    ) {
      return false;
    }
  } else {
    const previous = parseFragment(document, previousHtml);
    const before = soleIsland(previous);
    if (!before) {
      return false;
    }
    // Everything else, including text and comments beside the island, must
    // be unchanged.
    before.removeAttribute('props');
    after.removeAttribute('props');
    if (previous.innerHTML !== next.innerHTML) {
      return false;
    }
  }
  // Changing props re-renders the island from scratch, so only change them
  // if they differ, for example not after a client-side update set them.
  if (
    decodeIslandProps(island.getAttribute('props')) !== decodeIslandProps(props)
  ) {
    island.setAttribute('props', props);
  }
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
  const removedElements = removed.filter(
    (node): node is Element => node.nodeType === Node.ELEMENT_NODE,
  );
  removedElements.forEach((element) =>
    Drupal?.detachBehaviors(element, null, 'unload'),
  );

  const fragment = range.createContextualFragment(html);
  range.deleteContents();
  // Unmount the removed Canvas islands, including nested ones, or their
  // components stay mounted on the removed markup for as long as the preview
  // document lives. Astro defers this to the next view transition, whose
  // astro:after-swap event the preview never fires, so unmount them now and
  // drop that deferred listener, which would keep them referenced.
  removedElements
    .flatMap((element) => [element, ...element.querySelectorAll('*')])
    .filter((element) => element.localName === 'canvas-island')
    .forEach((island) => {
      const { unmount } = island as Element & { unmount?: () => void };
      if (unmount) {
        document.removeEventListener('astro:after-swap', unmount);
        unmount();
      }
    });
  parent.insertBefore(fragment, boundary.end);
  // Behaviors find their targets below the context, so the context must
  // contain the new markup's root elements. once() keeps unchanged siblings
  // from being initialized twice.
  if (parent.nodeType === Node.ELEMENT_NODE) {
    Drupal?.attachBehaviors(parent);
  }
  return true;
}

// The canvas marker a node is, if it is one.
const markerOf = (node: Node) =>
  node.nodeType === Node.COMMENT_NODE
    ? parseCanvasCommentMarker(node.nodeValue ?? '')
    : null;

// The markers of a live preview document, as markersOf() finds them in a
// render.
function liveMarkers(document: Document) {
  const walker = document.createTreeWalker(
    document,
    NodeFilter.SHOW_COMMENT | NodeFilter.SHOW_ELEMENT,
  );
  let markers = '';
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const failed =
      node.nodeType === Node.ELEMENT_NODE &&
      (node as Element).localName === 'div' &&
      (node as Element).getAttribute('data-component-uuid');
    if (typeof failed === 'string') {
      markers += failedMarker(failed);
    } else if (markerOf(node)) {
      markers += `<!-- ${node.nodeValue!.trim()} -->`;
    }
  }
  return markers;
}

/**
 * Shows a new render in a live preview document without loading it.
 *
 * Only the component instances whose own markup changed are replaced, with
 * the instances in them (see applyInPlaceUpdate). The others stay as they
 * are, so their Canvas islands don't render again. Instances aren't added,
 * moved or removed: when the render may have them elsewhere than the document
 * does, the document must show them there already, as it does once the edit
 * was shown ahead of the server (see optimisticPreview.ts).
 *
 * A Canvas island that stays keeps the markup of its slots from when it last
 * rendered, like when an instance in one of them is replaced. It shows that
 * markup again if it removes a slot and renders it again, until its props
 * change or the whole document is loaded.
 *
 * @param document
 *   The live preview document.
 * @param previous
 *   The render the document was last shown.
 * @param next
 *   The render to show.
 *
 * @return
 *   False when it can't be shown this way. The caller must then load the
 *   whole document.
 */
export function updateInPlace(
  document: Document,
  previous: string,
  next: string,
): boolean {
  const updates = findInPlaceUpdates(previous, next);
  const markers = markersOf(next);
  // Whether the document may have instances elsewhere than the render does.
  const moved =
    markers !== markersOf(previous) || isPreviewLayoutEdited(document);
  if (!updates || (moved && markers !== liveMarkers(document))) {
    return false;
  }
  if (!updates.every((update) => applyInPlaceUpdate(document, update))) {
    return false;
  }
  notePreviewRendered(document);
  // The head of the render is the one the document was loaded with. CSS that
  // was added to show a component ahead of the server isn't in it.
  document.head
    .querySelectorAll(`[${OPTIMISTIC_CSS_ATTRIBUTE}]`)
    .forEach((el) => el.remove());
  if (moved) {
    syncPlaceholders(document);
  }
  return true;
}
