/**
 * @file
 * DOM helpers for editing text props in place, inside the preview iframe.
 *
 * These helpers find where each text prop of a component instance is rendered
 * in the preview, turn that DOM into an editable host, and read plain text
 * back. Rich text is edited by CKEditor 5 on the same host.
 *
 * A prop's rendered DOM is found, in order of precedence, from:
 * 1. an element with `data-canvas-prop="{prop}"`, set by a component developer;
 * 2. the `<!-- canvas-prop-start-… -->` comments Drupal emits around Twig
 *    prints of the prop (see \Drupal\canvas\Twig\CanvasPropVisitor);
 * 3. the one element of the component whose text is the stored value.
 *
 * @see docs/react-codebase/inline-text-editing.md
 */
import { discoverCanvasPropBoundaries } from '@drupal-canvas/preview-geometry';

import { getPendingInlineValue } from '@/features/layout/previewOverlay/inlineText/commitInlineText';

import type { CanvasPropBoundary } from '@drupal-canvas/preview-geometry';
import type { ComponentModel } from '@/features/layout/layoutModelSlice';
import type { FieldDataItem } from '@/types/Component';

export type InlineTextKind =
  // `string`: one line of plain text.
  | 'plain'
  // `string_long`: plain text that keeps line breaks.
  | 'multiline'
  // `text_long`: formatted text with paragraphs.
  | 'richBlock'
  // `text`: formatted text on one line.
  | 'richInline';

/** Where a prop is rendered: a range between markers, or an element. */
export type InlinePropTarget =
  | { type: 'markers'; boundary: CanvasPropBoundary }
  | { type: 'element'; element: HTMLElement };

export interface InlineEditableProp {
  propName: string;
  kind: InlineTextKind;
  format?: string;
  required: boolean;
  target: InlinePropTarget;
}

/** Marks an element as the rendered output of a prop. */
export const PROP_ATTRIBUTE = 'data-canvas-prop';
/** Set to "false" on an element to keep the props inside it sidebar-only. */
export const OPT_OUT_ATTRIBUTE = 'data-canvas-inline-edit';
const DEBUG_STORAGE_KEY = 'canvas.debugInlineEditing';

export const isRich = (kind: InlineTextKind) =>
  kind === 'richBlock' || kind === 'richInline';

/**
 * Classifies a prop as inline-editable, or explains why it is not.
 *
 * Only props stored as a literal in the layout qualify: a prop linked to an
 * entity field or an adapter has no value of its own to edit in place.
 */
export function getInlineTextKind(
  propSource: FieldDataItem | undefined,
  instanceSourceType: string | undefined,
): { kind: InlineTextKind; format?: string } | { reason: string } {
  if (!propSource) {
    return { reason: 'not a prop of this component' };
  }
  if ((propSource.sourceTypeSettings?.cardinality ?? 1) !== 1) {
    return { reason: 'has multiple values' };
  }
  // A source type that differs from the component default means the prop was
  // linked to something else, such as an entity field.
  if (instanceSourceType && instanceSourceType !== propSource.sourceType) {
    return { reason: `is linked (${instanceSourceType})` };
  }
  switch (propSource.sourceType) {
    case 'static:field_item:string':
      return { kind: 'plain' };
    case 'static:field_item:string_long':
      return { kind: 'multiline' };
    case 'static:field_item:text_long':
    case 'static:field_item:text': {
      const formats = (
        propSource.sourceTypeSettings?.instance as
          { allowed_formats?: string[] } | undefined
      )?.allowed_formats;
      if (formats?.length !== 1) {
        return { reason: 'does not have exactly one allowed text format' };
      }
      return {
        kind:
          propSource.sourceType === 'static:field_item:text'
            ? 'richInline'
            : 'richBlock',
        format: formats[0],
      };
    }
    default:
      return { reason: `is not text (${propSource.sourceType})` };
  }
}

/** Normalizes text the way the plain readers do, for comparisons. */
const normalizeText = (text: string) =>
  text
    .replace(/\u00a0/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

/** Parses an HTML value without running anything in it. */
const parseHtml = (html: string) =>
  new DOMParser().parseFromString(html, 'text/html').body;

/** Returns the nodes between a boundary's markers. */
function nodesBetween(boundary: CanvasPropBoundary): ChildNode[] {
  const nodes: ChildNode[] = [];
  let node = boundary.start.nextSibling;
  while (node && node !== boundary.end) {
    nodes.push(node);
    node = node.nextSibling;
  }
  // Markers that do not share a parent cannot be turned into one host.
  return node === boundary.end ? nodes : [];
}

/** The nodes a target covers. */
function targetNodes(target: InlinePropTarget): ChildNode[] {
  return target.type === 'markers'
    ? nodesBetween(target.boundary)
    : Array.from(target.element.childNodes);
}

/** A range over the content of a target. */
export function targetRange(target: InlinePropTarget): Range {
  if (target.type === 'element') {
    const range = target.element.ownerDocument.createRange();
    range.selectNodeContents(target.element);
    return range;
  }
  const range = target.boundary.start.ownerDocument.createRange();
  // Markers are out of the document while their prop is edited; an empty
  // range then measures nothing.
  if (!target.boundary.start.parentNode || !target.boundary.end.parentNode) {
    return range;
  }
  range.setStartAfter(target.boundary.start);
  range.setEndBefore(target.boundary.end);
  return range;
}

/**
 * The element that becomes the editing host when markers are the only
 * meaningful content of their parent, as in `<h2>{{ heading }}</h2>`.
 */
function soleParentOfMarkers(boundary: CanvasPropBoundary): HTMLElement | null {
  const parent = boundary.start.parentElement;
  if (!parent || boundary.end.parentElement !== parent) {
    return null;
  }
  const inside = new Set<Node>(nodesBetween(boundary));
  return Array.from(parent.childNodes).every(
    (node) =>
      inside.has(node) ||
      node.nodeType === Node.COMMENT_NODE ||
      (node.nodeType === Node.TEXT_NODE && !node.textContent?.trim()),
  )
    ? parent
    : null;
}

/** The element that becomes the editing host for a target, if it is one. */
export function getTargetHostElement(
  target: InlinePropTarget,
): HTMLElement | null {
  return target.type === 'element'
    ? target.element
    : soleParentOfMarkers(target.boundary);
}

/**
 * Where a click edits a target: the box of the element that becomes the
 * editing host, so clicking beside short text in a wide heading works too,
 * or the text itself when the target shares its element with other content.
 */
export function targetHitRects(target: InlinePropTarget): DOMRect[] {
  const element =
    target.type === 'element'
      ? target.element
      : soleParentOfMarkers(target.boundary);
  return element
    ? [element.getBoundingClientRect()]
    : Array.from(targetRange(target).getClientRects());
}

const targetElement = (target: InlinePropTarget): Element | null =>
  target.type === 'element'
    ? target.element
    : target.boundary.start.parentElement;

/** The text of a stored value, for comparison with rendered text. */
const storedText = (value: unknown, rich: boolean): string | null => {
  if (typeof value !== 'string') {
    return value === undefined || value === null ? '' : null;
  }
  return rich
    ? normalizeText(parseHtml(value).textContent ?? '')
    : normalizeText(value);
};

/**
 * Lists a component's own elements, leaving out those of child components,
 * in document order.
 */
function componentOwnElements(doc: Document, componentUuid: string): Element[] {
  const walker = doc.createTreeWalker(
    doc,
    NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_COMMENT,
  );
  const elements: Element[] = [];
  // Depth of component markers inside the wanted component.
  let depth = -1;
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if (node.nodeType === Node.COMMENT_NODE) {
      const value = (node.nodeValue ?? '').trim();
      if (value === `canvas-start-${componentUuid}`) {
        depth = 0;
      } else if (depth >= 0 && value === `canvas-end-${componentUuid}`) {
        break;
      } else if (depth >= 0 && value.startsWith('canvas-start-')) {
        depth += 1;
      } else if (depth > 0 && value.startsWith('canvas-end-')) {
        depth -= 1;
      }
      continue;
    }
    if (depth === 0) {
      elements.push(node as Element);
    }
  }
  return elements;
}

/**
 * Finds the element showing a value that has no markers.
 *
 * Plain text: the one innermost element whose text is the value. Rich text:
 * the one element whose children are the value's top-level blocks, so the
 * editor gets the container of `<p>…</p>`, not the paragraph itself.
 */
function inferTarget(
  elements: Element[],
  text: string,
  richValue: string | null,
): HTMLElement | null {
  const matches = (element: Element) =>
    normalizeText(element.textContent ?? '') === text;
  const innermost = elements.filter(
    (element) =>
      matches(element) &&
      !Array.from(element.children).some((child) => matches(child)),
  );
  if (innermost.length !== 1) {
    return null;
  }
  if (richValue === null) {
    return innermost[0] as HTMLElement;
  }
  const blocks = Array.from(parseHtml(richValue).children).map(
    (child) => child.localName,
  );
  if (!blocks.length) {
    return innermost[0] as HTMLElement;
  }
  const own = new Set(elements);
  for (
    let element: Element | null = innermost[0];
    element && own.has(element) && matches(element);
    element = element.parentElement
  ) {
    const children = Array.from(element.children).map(
      (child) => child.localName,
    );
    if (children.join() === blocks.join()) {
      return element as HTMLElement;
    }
  }
  return null;
}

interface CachedLookup {
  model: unknown;
  propSources: unknown;
  props: InlineEditableProp[];
  rejected: Record<string, string>;
  /** Empty props whose element the template does not render. */
  unrendered: string[];
}

// Results per preview document, component and model, since lookups run on
// pointer moves. Any change to the document clears them, such as a code
// component that finishes hydrating.
const cache = new WeakMap<Document, Map<string, CachedLookup>>();

function documentCache(doc: Document): Map<string, CachedLookup> {
  let perDocument = cache.get(doc);
  if (!perDocument) {
    const created = new Map<string, CachedLookup>();
    perDocument = created;
    cache.set(doc, created);
    const Observer = doc.defaultView?.MutationObserver ?? MutationObserver;
    // Only the components a change is in lose their results: themes with
    // carousels or counters change the document all the time.
    new Observer((records) => {
      for (const record of records) {
        const element =
          record.target.nodeType === Node.ELEMENT_NODE
            ? (record.target as Element)
            : record.target.parentElement;
        // The component the change is in, and the components around it.
        let root = element?.closest<HTMLElement>('[data-canvas-uuid]');
        if (!root) {
          created.clear();
          return;
        }
        while (root) {
          created.delete(root.dataset.canvasUuid!);
          root = root.parentElement?.closest<HTMLElement>('[data-canvas-uuid]');
        }
      }
    }).observe(doc, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      // Starting and ending an edit, too: a prop's text is checked against
      // the model, and differs from it while edited.
      attributeFilter: [
        PROP_ATTRIBUTE,
        OPT_OUT_ATTRIBUTE,
        'data-canvas-inline-editing',
      ],
    });
  }
  return perDocument;
}

/**
 * Why props of a component instance are not editable in place, from the last
 * lookup, keyed by prop name.
 */
export function getInlineEditingRejections(
  doc: Document,
  componentUuid: string,
): Record<string, string> {
  return cache.get(doc)?.get(componentUuid)?.rejected ?? {};
}

/**
 * Empty text props of a component instance whose element is not rendered, so
 * they can only be added in the sidebar, from the last lookup.
 */
export function getUnrenderedEmptyProps(
  doc: Document,
  componentUuid: string,
): string[] {
  return cache.get(doc)?.get(componentUuid)?.unrendered ?? [];
}

/**
 * Lists the props of one component instance that can be edited in place.
 *
 * A prop qualifies when it is a supported text prop, it is rendered exactly
 * once in a place Canvas can find, and the rendered text is the stored value.
 * The last check rejects output the editor could not write back faithfully,
 * such as a value passed through `|upper`, or a same-named variable in an
 * included template.
 *
 * Set `localStorage['canvas.debugInlineEditing'] = '1'` to log why props are
 * not editable.
 */
export function findInlineEditableProps(
  doc: Document,
  componentUuid: string,
  propSources: Record<string, FieldDataItem> | undefined,
  model: ComponentModel | undefined,
): InlineEditableProp[] {
  if (!propSources || !model) {
    return [];
  }
  const perDocument = documentCache(doc);
  const cached = perDocument.get(componentUuid);
  if (cached?.model === model && cached.propSources === propSources) {
    return cached.props;
  }

  const source = 'source' in model ? (model.source as Record<string, any>) : {};
  const elements = componentOwnElements(doc, componentUuid);
  const boundaries = discoverCanvasPropBoundaries(doc).filter(
    (boundary) => boundary.componentUuid === componentUuid,
  );
  const rejected: Record<string, string> = {};
  const unrendered: string[] = [];
  const props: InlineEditableProp[] = [];
  const claimed = new Map<Element, string>();
  const contested = new Set<Element>();

  Object.keys(propSources).forEach((propName) => {
    const classified = getInlineTextKind(
      propSources[propName],
      source[propName]?.sourceType,
    );
    if ('reason' in classified) {
      rejected[propName] = classified.reason;
      return;
    }
    const rich = isRich(classified.kind);
    // While a commit of the prop is in flight, the preview already shows its
    // value, and the model does not yet.
    const resolved =
      getPendingInlineValue(componentUuid, propName) ??
      model.resolved[propName];
    const text = storedText(resolved, rich);
    if (text === null) {
      rejected[propName] = 'has a value that is not text';
      return;
    }

    let target: InlinePropTarget | null = null;
    const explicit = elements.filter(
      (element) => element.getAttribute(PROP_ATTRIBUTE) === propName,
    );
    const marked = boundaries.filter(
      (boundary) => boundary.propName === propName,
    );
    if (explicit.length > 1 || (!explicit.length && marked.length > 1)) {
      rejected[propName] = 'is rendered more than once';
      return;
    }
    if (explicit.length === 1) {
      target = { type: 'element', element: explicit[0] as HTMLElement };
    } else if (marked.length === 1) {
      if (marked[0].start.parentNode !== marked[0].end.parentNode) {
        rejected[propName] = 'has markers that do not share a parent element';
        return;
      }
      target = { type: 'markers', boundary: marked[0] };
    } else if (text) {
      const element = inferTarget(
        elements,
        text,
        rich && typeof resolved === 'string' ? resolved : null,
      );
      if (element) {
        target = { type: 'element', element };
      }
    }
    if (!target) {
      if (!text) {
        unrendered.push(propName);
      }
      rejected[propName] = text
        ? 'is not found in the preview: no markers, and its text is not unique in the component'
        : 'is empty and not found in the preview: mark the element that shows it with data-canvas-prop to add it in place';
      return;
    }
    const nodes = targetNodes(target);
    const renderedText = normalizeText(
      nodes.map((node) => node.textContent ?? '').join(''),
    );
    if (renderedText !== text) {
      rejected[propName] = `renders as "${renderedText}", not its stored value`;
      return;
    }
    if (targetElement(target)?.closest(`[${OPT_OUT_ATTRIBUTE}="false"]`)) {
      rejected[propName] = `is inside [${OPT_OUT_ATTRIBUTE}="false"]`;
      return;
    }
    // Two props cannot share one element, such as a heading and a button
    // label with the same inferred text.
    const element = targetElement(target)!;
    if (target.type === 'element' && claimed.has(element)) {
      rejected[propName] = `shares its element with "${claimed.get(element)}"`;
      rejected[claimed.get(element)!] = `shares its element with "${propName}"`;
      contested.add(element);
      return;
    }
    claimed.set(element, propName);
    props.push({
      propName,
      ...classified,
      required: propSources[propName].required === true,
      target,
    });
  });

  // Drop props whose element turned out to be shared: which prop it shows
  // cannot be told.
  const result = props.filter(
    (prop) =>
      prop.target.type !== 'element' || !contested.has(prop.target.element),
  );
  perDocument.set(componentUuid, {
    model,
    propSources,
    props: result,
    rejected,
    unrendered,
  });
  if (Object.keys(rejected).length && isDebugging()) {
    console.info(
      `Canvas inline editing: props of ${componentUuid} that stay sidebar-only`,
      rejected,
    );
  }
  return result;
}

/** Whether `localStorage['canvas.debugInlineEditing']` is `1`. */
export function isDebugging(): boolean {
  try {
    return window.localStorage.getItem(DEBUG_STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

/**
 * Lists the marked output of a component's props, editable in place or not:
 * elements with `data-canvas-prop`, and marker ranges that can be measured.
 */
export function listMarkedPropTargets(
  doc: Document,
  componentUuid: string,
): Array<{ propName: string; target: InlinePropTarget }> {
  return [
    ...componentOwnElements(doc, componentUuid)
      .filter((element) => element.hasAttribute(PROP_ATTRIBUTE))
      .map((element) => ({
        propName: element.getAttribute(PROP_ATTRIBUTE)!,
        target: { type: 'element', element } as InlinePropTarget,
      })),
    ...discoverCanvasPropBoundaries(doc)
      .filter(
        (boundary) =>
          boundary.componentUuid === componentUuid &&
          boundary.start.parentNode === boundary.end.parentNode,
      )
      .map((boundary) => ({
        propName: boundary.propName,
        target: { type: 'markers', boundary } as InlinePropTarget,
      })),
  ];
}

/**
 * Names the prop rendered under a point, editable in place or not, when its
 * output is marked. Used to send the author to the sidebar for text that can
 * only be edited there.
 */
export function findMarkedPropNameAtPoint(
  doc: Document,
  componentUuid: string,
  x: number,
  y: number,
): string | null {
  return (
    listMarkedPropTargets(doc, componentUuid).find(({ target }) =>
      targetHitRects(target).some((rect) => rectContains(rect, x, y)),
    )?.propName ?? null
  );
}

/** Converts a point in the parent document to the iframe's CSS pixels. */
export function toIframePoint(
  iframe: HTMLIFrameElement,
  clientX: number,
  clientY: number,
): { x: number; y: number } {
  const rect = iframe.getBoundingClientRect();
  // The iframe sits inside a `transform: scale()` container when zoomed.
  const scale = iframe.offsetWidth ? rect.width / iframe.offsetWidth : 1;
  return {
    x: (clientX - rect.left) / scale - iframe.clientLeft,
    y: (clientY - rect.top) / scale - iframe.clientTop,
  };
}

/** Returns the caret position closest to a point in the iframe. */
export function caretFromPoint(
  doc: Document,
  x: number,
  y: number,
): { node: Node; offset: number } | null {
  if (typeof doc.caretPositionFromPoint === 'function') {
    const position = doc.caretPositionFromPoint(x, y);
    return position
      ? { node: position.offsetNode, offset: position.offset }
      : null;
  }
  // Safari before 26 only has the WebKit API.
  const range = (doc as any).caretRangeFromPoint?.(x, y) as Range | null;
  return range
    ? { node: range.startContainer, offset: range.startOffset }
    : null;
}

const HIT_TOLERANCE = 4;

const rectContains = (rect: DOMRect, x: number, y: number) =>
  x >= rect.left - HIT_TOLERANCE &&
  x <= rect.right + HIT_TOLERANCE &&
  y >= rect.top - HIT_TOLERANCE &&
  y <= rect.bottom + HIT_TOLERANCE;

/**
 * Finds the editable prop whose rendered text is under a point, in iframe CSS
 * pixels.
 *
 * This is purely geometric. Hit-testing APIs return whatever is on top, and
 * themes often stack a transparent layer over text, such as the
 * `::before { position: absolute; inset: 0 }` of a stretched card link.
 */
export function findInlineEditablePropAtPoint(
  props: InlineEditableProp[],
  x: number,
  y: number,
): InlineEditableProp | null {
  let best: InlineEditableProp | null = null;
  let bestArea = Infinity;
  props.forEach((prop) => {
    const rects = targetHitRects(prop.target);
    if (!rects.some((rect) => rectContains(rect, x, y))) {
      return;
    }
    // Prefer the innermost prop when ranges nest.
    const area = rects.reduce((sum, rect) => sum + rect.width * rect.height, 0);
    if (area < bestArea) {
      best = prop;
      bestArea = area;
    }
  });
  return best;
}

/**
 * Returns the caret position in an element's text closest to a point.
 *
 * Falls back to measuring characters when the hit-testing API resolves the
 * point to a layer stacked above the text.
 */
export function caretInElementFromPoint(
  element: HTMLElement,
  x: number,
  y: number,
): { node: Node; offset: number } | null {
  const doc = element.ownerDocument;
  const caret = caretFromPoint(doc, x, y);
  if (caret && element.contains(caret.node)) {
    return caret;
  }
  // ponytail: measures every character, fine for prop-sized text; switch to a
  // per-line binary search if long-form bodies make this slow.
  const walker = doc.createTreeWalker(element, NodeFilter.SHOW_TEXT);
  const range = doc.createRange();
  let best: { node: Node; offset: number } | null = null;
  let bestDistance = Infinity;
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const length = node.textContent?.length ?? 0;
    for (let offset = 0; offset < length; offset++) {
      range.setStart(node, offset);
      range.setEnd(node, offset + 1);
      const rect = range.getBoundingClientRect();
      if (!rect.width && !rect.height) {
        continue;
      }
      const dy =
        y < rect.top ? rect.top - y : y > rect.bottom ? y - rect.bottom : 0;
      const dx =
        x < rect.left ? rect.left - x : x > rect.right ? x - rect.right : 0;
      // Lines first, then the horizontal distance within a line.
      const distance = dy * 10000 + dx;
      if (distance < bestDistance) {
        bestDistance = distance;
        const after = x > rect.left + rect.width / 2;
        best = { node, offset: after ? offset + 1 : offset };
      }
    }
  }
  return best;
}

export interface InlineEditingHost {
  element: HTMLElement;
  /** Puts back the content the host had before editing. */
  revert: () => void;
  /** Restores the DOM to a non-editable state, keeping the edited content. */
  release: () => void;
  /**
   * Removes the wrapper element, if one was added. Kept apart from release()
   * so a failed commit can still revert the content.
   */
  unwrap: () => void;
}

/**
 * Makes a prop's rendered DOM editable and returns the editable element.
 *
 * An element target is edited as is. For markers that are the only meaningful
 * content of their parent, as in `<h2>{{ heading }}</h2>`, the parent becomes
 * editable, so the caret, focus ring and click target match the rendered
 * block exactly. Otherwise the range is moved into a wrapper element.
 */
export function createInlineEditingHost(
  prop: InlineEditableProp,
): InlineEditingHost {
  const { target } = prop;
  const markers =
    target.type === 'markers'
      ? [target.boundary.start, target.boundary.end]
      : [];
  let element: HTMLElement;
  let unwrap: (() => void) | null = null;
  if (target.type === 'element') {
    element = target.element;
  } else {
    const { start } = target.boundary;
    const inside = nodesBetween(target.boundary);
    const parent = soleParentOfMarkers(target.boundary);
    if (parent) {
      element = parent;
    } else {
      element = start.ownerDocument.createElement(
        prop.kind === 'richBlock' ? 'div' : 'span',
      );
      element.append(...inside);
      start.after(element);
      unwrap = () => {
        if (element.isConnected) {
          element.replaceWith(...Array.from(element.childNodes));
        }
      };
    }
  }
  const doc = element.ownerDocument;

  // Copies of the content before editing; the markers themselves are kept,
  // since they identify the prop.
  const snapshot = Array.from(element.childNodes).map((node) =>
    markers.includes(node as Comment) ? node : node.cloneNode(true),
  );
  // An element that only holds the prop is edited without its markers, so
  // the caret cannot end up outside them, as it would in an empty prop:
  // the element's whole text is the value. release() puts them back.
  if (markers.length && !unwrap) {
    markers.forEach((marker) => marker.remove());
  }

  const attributes = [
    'contenteditable',
    'role',
    'aria-label',
    'aria-multiline',
    'spellcheck',
    'data-canvas-inline-editing',
    'data-canvas-inline-placeholder',
    'data-canvas-inline-empty',
  ];
  const previous = new Map(
    attributes.map((name) => [name, element.getAttribute(name)]),
  );
  const previousStyle = element.getAttribute('style');
  // CKEditor 5 manages the editing attributes of rich text hosts itself.
  if (!isRich(prop.kind)) {
    element.setAttribute('contenteditable', 'plaintext-only');
    element.setAttribute('role', 'textbox');
    element.setAttribute('aria-multiline', String(prop.kind !== 'plain'));
    element.setAttribute('spellcheck', 'true');
  }
  element.setAttribute('data-canvas-inline-editing', prop.kind);
  // Lift the text above transparent layers the theme may stack over it, so
  // clicks and drags inside the text reach it. Relative positioning without
  // offsets does not move a statically positioned element.
  if (doc.defaultView?.getComputedStyle(element).position === 'static') {
    element.style.position = 'relative';
  }
  element.style.zIndex = '2147483647';

  return {
    element,
    revert: () => {
      element.replaceChildren(
        ...snapshot.map((node) =>
          markers.includes(node as Comment) ? node : node.cloneNode(true),
        ),
      );
    },
    release: () => {
      previous.forEach((value, name) =>
        value === null
          ? element.removeAttribute(name)
          : element.setAttribute(name, value),
      );
      if (previousStyle === null) {
        element.removeAttribute('style');
      } else {
        element.setAttribute('style', previousStyle);
      }
      // An editor replacing the host's content also drops the markers inside
      // it; put them back so the prop stays discoverable.
      if (markers.length && !unwrap) {
        if (!markers[0].isConnected) {
          element.prepend(markers[0]);
        }
        if (!markers[1].isConnected) {
          element.append(markers[1]);
        }
      }
    },
    unwrap: () => unwrap?.(),
  };
}

const LINE_BREAKING = new Set(['br', 'div', 'p', 'li']);

/**
 * Reads the value of a plain text editing host.
 *
 * Reads the DOM, not `innerText`, so line breaks survive themes that collapse
 * them with `white-space: normal`.
 */
export function readPlainValue(
  element: HTMLElement,
  kind: 'plain' | 'multiline',
  prop?: InlineEditableProp,
): string {
  const target = prop?.target;
  const nodes =
    target?.type === 'markers' &&
    target.boundary.start.parentNode === element &&
    target.boundary.end.parentNode === element
      ? nodesBetween(target.boundary)
      : Array.from(element.childNodes);
  let text = '';
  const walk = (node: Node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      text += node.textContent ?? '';
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) {
      return;
    }
    const name = (node as Element).localName;
    // Browsers break lines in editable text with <br>, or with new blocks.
    if (LINE_BREAKING.has(name) && text && !text.endsWith('\n')) {
      text += '\n';
    }
    node.childNodes.forEach(walk);
  };
  nodes.forEach(walk);
  text = text.replace(/\u00a0/g, ' ').replace(/\r\n?/g, '\n');
  return kind === 'plain'
    ? normalizeText(text)
    : text
        .split('\n')
        .map((line) => line.replace(/[ \t]+/g, ' ').trimEnd())
        .join('\n')
        .trim();
}

const SAFE_PROTOCOLS = new Set(['http:', 'https:', 'mailto:', 'tel:']);

/**
 * Allows relative URLs, fragments and the http(s), mailto and tel schemes.
 *
 * Parses like a browser does, so tabs or control characters inside a scheme
 * such as `java\tscript:` cannot slip through.
 */
export function isSafeHref(href: string): boolean {
  if (!href.trim()) {
    return false;
  }
  try {
    return SAFE_PROTOCOLS.has(
      new URL(href, 'https://relative.invalid/').protocol,
    );
  } catch {
    return false;
  }
}

/**
 * Turns what an author typed in a link field into a URL.
 *
 * `/about` and `#top` stay relative, `example.com` gets the default protocol,
 * and a bare word such as `about` becomes the path `/about`.
 */
export function normalizeLinkInput(
  input: string,
  defaultProtocol = 'https://',
): string {
  const value = input.trim();
  if (/^[a-z][a-z0-9+.-]*:/i.test(value) || /^[/#?]/.test(value)) {
    return value;
  }
  if (/^[^/\s]+@[^/\s]+\.[^/\s]+$/.test(value)) {
    return `mailto:${value}`;
  }
  const host = value.split('/')[0];
  return host.includes('.') ? `${defaultProtocol}${value}` : `/${value}`;
}

/** Converts a prop machine name to a label: `heading_text` → "Heading text". */
export function humanizePropName(propName: string): string {
  const words = propName.replace(/[_-]+/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}
