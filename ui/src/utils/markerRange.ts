/**
 * Marker-range utilities for the preview DOM.
 *
 * Every component instance in the preview is delimited by HTML comments
 * (`<!-- canvas-start-{uuid} -->` … `<!-- canvas-end-{uuid} -->`). These
 * utilities locate, replace, remove, relocate, and clone the node range
 * between a component's markers, enabling in-place preview updates without a
 * full document reload.
 */

export interface MarkerRange {
  start: Comment;
  end: Comment;
}

const commentWalker = (root: Node, doc: Document) =>
  doc.createTreeWalker(root, NodeFilter.SHOW_COMMENT);

/**
 * Finds the comment markers delimiting a component instance.
 */
export function findMarkerRange(
  doc: Document,
  uuid: string,
): MarkerRange | null {
  const walker = commentWalker(doc, doc);
  let start: Comment | null = null;
  let node = walker.nextNode();
  while (node) {
    const value = node.nodeValue?.trim();
    if (value === `canvas-start-${uuid}`) {
      start = node as Comment;
    } else if (value === `canvas-end-${uuid}` && start) {
      return { start, end: node as Comment };
    }
    node = walker.nextNode();
  }
  return null;
}

/**
 * All sibling nodes from the start marker to the end marker, inclusive.
 *
 * Returns null when the markers are not siblings (malformed markup).
 */
export function getMarkerRangeNodes(range: MarkerRange): Node[] | null {
  const nodes: Node[] = [];
  let node: Node | null = range.start;
  while (node) {
    nodes.push(node);
    if (node === range.end) {
      return nodes;
    }
    node = node.nextSibling;
  }
  return null;
}

/**
 * Extracts one component's marker-delimited chunk from a full HTML document
 * string. The markers are plain text in the HTML, so a string scan avoids a
 * DOMParser round-trip.
 */
export function extractMarkerRangeHtml(
  html: string,
  uuid: string,
): string | null {
  const startMatch = new RegExp(`<!--\\s*canvas-start-${uuid}\\s*-->`).exec(
    html,
  );
  const endMatch = new RegExp(`<!--\\s*canvas-end-${uuid}\\s*-->`).exec(html);
  if (!startMatch || !endMatch || endMatch.index < startMatch.index) {
    return null;
  }
  return html.slice(startMatch.index, endMatch.index + endMatch[0].length);
}

/**
 * Parses HTML into a fragment whose script elements execute on insertion.
 *
 * Scripts parsed via innerHTML never execute; fragments from
 * createContextualFragment do, which server-rendered component markup (e.g.
 * astro island initializers) relies on. The fragment is created by the target
 * document so custom elements upgrade against that document's registry.
 */
export function createExecutableFragment(
  doc: Document,
  html: string,
): DocumentFragment {
  return doc.createRange().createContextualFragment(html);
}

/**
 * Replaces a component's marker range (inclusive) with new markup.
 *
 * The incoming markup carries its own fresh markers, so the old markers are
 * removed together with the old content.
 *
 * @returns The nodes inserted, or null when the range could not be located.
 */
export function replaceMarkerRange(
  doc: Document,
  uuid: string,
  html: string,
): Node[] | null {
  const range = findMarkerRange(doc, uuid);
  if (!range) {
    return null;
  }
  const nodes = getMarkerRangeNodes(range);
  if (!nodes) {
    return null;
  }
  const fragment = createExecutableFragment(doc, html);
  const inserted = Array.from(fragment.childNodes);
  range.start.before(fragment);
  nodes.forEach((node) => (node as ChildNode).remove());
  return inserted;
}

/**
 * Removes a component's marker range (inclusive) from the document.
 */
export function removeMarkerRange(doc: Document, uuid: string): boolean {
  const range = findMarkerRange(doc, uuid);
  if (!range) {
    return false;
  }
  const nodes = getMarkerRangeNodes(range);
  if (!nodes) {
    return false;
  }
  nodes.forEach((node) => (node as ChildNode).remove());
  return true;
}

/**
 * An insertion point in the preview DOM for a component range.
 *
 * `before` is the node the range must be inserted before; when null, the
 * range is appended to `parent`.
 */
export interface InsertionPoint {
  parent: Node;
  before: Node | null;
}

/**
 * Resolves the DOM insertion point for a component based on its position in
 * the client layout: the destination container (a slot, the content region,
 * or a global region) plus the uuid of the component it should precede.
 *
 * @param doc - The preview document.
 * @param container - Either a slot ("{parentUuid}/{slotName}") or a region id.
 * @param beforeUuid - The uuid of the next sibling component in the layout,
 *   or null to append at the end of the container.
 */
export function findInsertionPoint(
  doc: Document,
  container: { slotId?: string; regionId?: string },
  beforeUuid: string | null,
): InsertionPoint | null {
  if (beforeUuid) {
    const siblingRange = findMarkerRange(doc, beforeUuid);
    if (siblingRange?.start.parentNode) {
      return {
        parent: siblingRange.start.parentNode,
        before: siblingRange.start,
      };
    }
    return null;
  }
  if (container.slotId) {
    // The slot-start comment's parent element contains the slot's components.
    const comment = findComment(doc, `canvas-slot-start-${container.slotId}`);
    if (comment?.parentNode) {
      return { parent: comment.parentNode, before: null };
    }
    return null;
  }
  if (container.regionId === 'content') {
    // The content region's container div is the parent of its markers; append
    // before the region end marker.
    const end = findComment(doc, 'canvas-region-end-content');
    if (end?.parentNode) {
      return { parent: end.parentNode, before: end };
    }
    return null;
  }
  if (container.regionId) {
    const end = findComment(doc, `canvas-region-end-${container.regionId}`);
    if (end?.parentNode) {
      return { parent: end.parentNode, before: end };
    }
  }
  return null;
}

function findComment(doc: Document, value: string): Comment | null {
  const walker = commentWalker(doc, doc);
  let node = walker.nextNode();
  while (node) {
    if (node.nodeValue?.trim() === value) {
      return node as Comment;
    }
    node = walker.nextNode();
  }
  return null;
}

/**
 * Moves a component's marker range (inclusive) to a new insertion point.
 */
export function moveMarkerRange(
  doc: Document,
  uuid: string,
  point: InsertionPoint,
): boolean {
  const range = findMarkerRange(doc, uuid);
  if (!range) {
    return false;
  }
  const nodes = getMarkerRangeNodes(range);
  if (!nodes) {
    return false;
  }
  for (const node of nodes) {
    point.parent.insertBefore(node, point.before);
  }
  return true;
}

/**
 * Clones a component's marker range, rewriting every uuid in comments and
 * attribute values according to the given map (old uuid to new uuid). Used
 * for optimistic duplication, where the duplicate subtree has fresh uuids.
 *
 * @returns The cloned nodes, or null when the source range is missing.
 */
export function cloneMarkerRangeWithUuidMap(
  doc: Document,
  sourceUuid: string,
  uuidMap: Record<string, string>,
  point: InsertionPoint,
): Node[] | null {
  const range = findMarkerRange(doc, sourceUuid);
  if (!range) {
    return null;
  }
  const nodes = getMarkerRangeNodes(range);
  if (!nodes) {
    return null;
  }
  const rewrite = (value: string): string => {
    let result = value;
    for (const [oldUuid, newUuid] of Object.entries(uuidMap)) {
      result = result.split(oldUuid).join(newUuid);
    }
    return result;
  };
  const clones = nodes.map((node) => node.cloneNode(true));
  for (const clone of clones) {
    if (clone.nodeType === Node.COMMENT_NODE && clone.nodeValue) {
      clone.nodeValue = rewrite(clone.nodeValue);
    }
    if (clone.nodeType === Node.ELEMENT_NODE) {
      const walker = commentWalker(clone, doc);
      let comment = walker.nextNode();
      while (comment) {
        if (comment.nodeValue) {
          comment.nodeValue = rewrite(comment.nodeValue);
        }
        comment = walker.nextNode();
      }
      const elements = [
        clone as Element,
        ...(clone as Element).querySelectorAll('*'),
      ];
      for (const element of elements) {
        for (const attr of Array.from(element.attributes)) {
          const rewritten = rewrite(attr.value);
          if (rewritten !== attr.value) {
            element.setAttribute(attr.name, rewritten);
          }
        }
      }
    }
  }
  for (const clone of clones) {
    point.parent.insertBefore(clone, point.before);
  }
  return clones;
}
