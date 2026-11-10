import {
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  parseCanvasCommentMarker,
  unionCanvasRects,
} from '@drupal-canvas/preview-geometry';

/**
 * The copy of a dragged component that follows the pointer in the preview.
 *
 * @see livePreview.ts
 */

// Embedded documents and media, which load when they are copied.
const EMBEDS = 'iframe, video, audio, object, embed';

export const SLIDE = { duration: 120, easing: 'cubic-bezier(0.2, 0, 0, 1)' };
export const reducedMotion = () =>
  window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches === true;
// Animations other than following the pointer only run without reduced motion.
export const timing = () => ({
  ...SLIDE,
  duration: reducedMotion() ? 0 : SLIDE.duration,
});
// The copy of a large component under the pointer shrinks to fit this size,
// down to 40% of its size, so it doesn't hide where it can be dropped.
const GHOST = { width: 480, height: 280, minScale: 0.4 };

export interface Point {
  x: number;
  y: number;
}

export interface Ghost {
  element: HTMLElement;
  animation: Animation;
  startTime: number;
  frame: number;
}

export const isElement = (node: Node): node is HTMLElement =>
  node.nodeType === Node.ELEMENT_NODE;

// Element.animate() is missing in test environments.
export const canAnimate = (document: Document) =>
  typeof document.body?.animate === 'function';

// The elements that render boxes, which can be measured and animated.
export const boxes = (el: Element): HTMLElement[] =>
  getComputedStyle(el).display === 'contents'
    ? Array.from(el.children).flatMap(boxes)
    : [el as HTMLElement];

// The rectangle of markup.
export function bounds(nodes: Node[]): DOMRect {
  const r = unionCanvasRects(
    nodes
      .filter(isElement)
      .flatMap(boxes)
      .map((el) => el.getBoundingClientRect()),
  );
  return r ? new DOMRect(r.left, r.top, r.width, r.height) : new DOMRect();
}

// Maps a point in the editor viewport to the preview viewport.
export function toPreview(document: Document, point: Point): Point | null {
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
export function outlineEmptySlots(root: ParentNode) {
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

/**
 * Lifts a copy of dragged markup under the pointer.
 *
 * The copy is fixed in the preview viewport and moved by an animation, which
 * changes no markup the overlay observes. Being a copy, it keeps the look of
 * the component wherever the component itself is shown.
 *
 * @param document
 *   The preview document.
 * @param nodes
 *   The dragged markup.
 * @param pointer
 *   Returns where the pointer is, in viewport coordinates of the editor.
 * @param grab
 *   Where in the copy the pointer holds it, if it appears there. Without it,
 *   the copy is lifted off the markup where the pointer is.
 */
export function liftGhost(
  document: Document,
  nodes: Node[],
  pointer: () => Point | undefined,
  grab?: Point,
): Ghost | undefined {
  const elements = nodes.filter(isElement);
  const start = pointer();
  const origin = start && toPreview(document, start);
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
      if (parseCanvasCommentMarker(walker.currentNode.nodeValue ?? '')) {
        markers.push(walker.currentNode);
      }
    }
    markers.forEach((marker) => marker.parentNode?.removeChild(marker));
    outlineEmptySlots(copy);
    element.append(copy);
    // A copy of an embed would load it a second time: keep its size only.
    const embeds = [el, ...el.querySelectorAll('*')].filter((e) =>
      e.matches(EMBEDS),
    );
    [copy, ...copy.querySelectorAll('*')]
      .filter((e) => e.matches(EMBEDS))
      .forEach((embed, i) => {
        const { width, height } = embeds[i].getBoundingClientRect();
        const box = document.createElement('div');
        box.style.cssText = `width:${width}px;height:${height}px;background:rgb(0 0 0 / 0.06)`;
        embed.replaceWith(box);
      });
    // A copy of a code component would hydrate, running it a second time:
    // keep only what it renders.
    element.querySelectorAll('canvas-island').forEach((island) => {
      const box = document.createElement('div');
      box.style.display = 'contents';
      box.append(...Array.from(island.childNodes));
      island.replaceWith(box);
    });
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
    // Its own layer, so moving it doesn't paint it or the page under it again.
    willChange: 'translate, scale, opacity',
  });
  // A component too tall to fit is cropped, fading out at the bottom.
  if (rect.height * scale > GHOST.height) {
    element.style.height = `${GHOST.height / scale}px`;
    element.style.maskImage =
      'linear-gradient(#000 calc(100% - 64px), transparent)';
  }
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
  const instant = reducedMotion();
  let shown = '';
  const follow = (time: number) => {
    const at = pointer();
    const p = at && toPreview(document, at);
    if (p) {
      const t = instant
        ? 1
        : Math.min(1, Math.max(0, time - ghost.startTime) / 100);
      const eased = 1 - (1 - t) ** 3;
      const keyframe = {
        translate: `${p.x - origin.x}px ${p.y - origin.y}px`,
        scale: String(from.scale + (scale - from.scale) * eased),
        opacity: String(from.opacity + (0.96 - from.opacity) * eased),
      };
      const key = `${keyframe.translate} ${keyframe.scale} ${keyframe.opacity}`;
      if (key !== shown) {
        shown = key;
        (ghost.animation.effect as KeyframeEffect).setKeyframes([keyframe]);
      }
    }
    ghost.frame = requestAnimationFrame(follow);
  };
  ghost.frame = requestAnimationFrame(follow);
  return ghost;
}

/**
 * Settles the copy of dragged markup where the markup lands, fading it out.
 *
 * @param target
 *   Where the markup lands. Without a size, e.g. for a canceled insert, the
 *   copy fades out where it is.
 */
export function dropGhost(ghost: Ghost, target: DOMRect) {
  cancelAnimationFrame(ghost.frame);
  const { element } = ghost;
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
    { ...timing(), fill: 'forwards' },
  );
}
