/**
 * @file
 * Heuristics for finding elements whose height is driven by viewport-relative
 * CSS (vh units, Tailwind's h-screen/min-h-screen/min-h-*, or an inline vh
 * style). These resolve against the current viewport height — inside a preview
 * iframe, the height the host last set. Measuring them, resizing the iframe to
 * fit, and re-measuring feeds back: the element grows because the iframe grew.
 * Detecting them lets callers neutralize them before measuring.
 */

import { STABLE_HEIGHT_ATTRIBUTE } from './stable-height';

/**
 * HTML elements expose className as a string; SVG elements use SVGAnimatedString.
 */
export function getClassNameString(element: Element): string {
  const cn = element.className;
  if (typeof cn === 'string') {
    return cn;
  }
  if (
    cn &&
    typeof cn === 'object' &&
    'baseVal' in cn &&
    typeof (cn as { baseVal: string }).baseVal === 'string'
  ) {
    return (cn as { baseVal: string }).baseVal;
  }
  return element.getAttribute('class') ?? '';
}

/**
 * Matches Tailwind height utilities that resolve against the viewport
 * (h-screen/dvh/svh/lvh, and their min-/max- variants, including arbitrary
 * values like h-[100vh]). Deliberately excludes plain min-h-<number>
 * utilities (e.g. min-h-20, min-h-96) — those are fixed sizes, not
 * viewport-relative, and matching them as a bare "min-h-" substring was
 * flagging unrelated elements for neutralization during measurement.
 */
const VIEWPORT_HEIGHT_CLASS_TOKEN =
  /^(?:min-|max-)?h-(?:screen|dvh|svh|lvh|\[[^\]]*\d(?:d|s|l)?vh[^\]]*\])$/;

function looksLikeVhClassOrInline(element: HTMLElement): boolean {
  const cls = getClassNameString(element);
  const styleAttr = element.getAttribute('style');
  const hasViewportHeightClass = cls
    .split(/\s+/)
    .some((token) => VIEWPORT_HEIGHT_CLASS_TOKEN.test(token));
  return (
    hasViewportHeightClass ||
    (styleAttr != null && /\d(?:d|s|l)?vh\b/.test(styleAttr))
  );
}

function approximatelyEquals(a: number, b: number): boolean {
  return Math.abs(a - b) <= 2;
}

/**
 * Catches CSS-file vh rules (e.g. a stylesheet with no Tailwind-style class
 * markers) by comparing computed height/min-height against the viewport
 * height and half the viewport height (for 50vh-style rules). Heights above
 * the viewport are also candidates: a probe rejects fixed or content-driven
 * boxes while allowing stylesheet rules such as `height: 150vh` to be found.
 */
function cssMatchesViewportHeuristic(
  element: HTMLElement,
  effectiveViewportHeight: number,
): boolean {
  const win = element.ownerDocument.defaultView;
  if (!win) {
    return false;
  }
  const computedStyle = win.getComputedStyle(element);
  const minHeight = parseFloat(computedStyle.minHeight);
  const height = parseFloat(computedStyle.height);
  if (
    (Number.isFinite(minHeight) && minHeight > effectiveViewportHeight + 2) ||
    (Number.isFinite(height) && height > effectiveViewportHeight + 2)
  ) {
    return true;
  }
  const targets = [effectiveViewportHeight, effectiveViewportHeight / 2];
  for (const target of targets) {
    if (Number.isFinite(minHeight) && approximatelyEquals(minHeight, target)) {
      return true;
    }
    if (Number.isFinite(height) && approximatelyEquals(height, target)) {
      return true;
    }
  }
  return false;
}

/**
 * Whether element's height is likely driven by viewport-relative CSS.
 * html/body are excluded: callers that need to neutralize those two
 * elements specifically already do so unconditionally.
 */
export function isVhMeasurementCandidate(
  element: HTMLElement,
  effectiveViewportHeight: number,
): boolean {
  if (['HTML', 'BODY'].includes(element.tagName)) {
    return false;
  }
  if (looksLikeVhClassOrInline(element)) {
    return true;
  }
  if (element.hasAttribute(STABLE_HEIGHT_ATTRIBUTE)) {
    return true;
  }
  return cssMatchesViewportHeuristic(element, effectiveViewportHeight);
}

/**
 * Walks root's subtree (root included) for elements whose height looks
 * viewport-relative.
 */
export function collectViewportRelativeElements(
  root: Element,
  effectiveViewportHeight: number,
): HTMLElement[] {
  const candidates: HTMLElement[] = [];
  const elements: Element[] = [root, ...Array.from(root.querySelectorAll('*'))];
  for (const element of elements) {
    if (
      element instanceof HTMLElement &&
      isVhMeasurementCandidate(element, effectiveViewportHeight)
    ) {
      candidates.push(element);
    }
  }
  return candidates;
}
