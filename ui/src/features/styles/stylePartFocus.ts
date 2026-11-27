import { useEffect } from 'react';

/**
 * Selecting a named part from the preview, as drilling into a layer in Figma:
 * clicking a part of the selected component opens its group in the Style tab.
 */

type Listener = (uuid: string, part: string) => void;
const listeners = new Set<Listener>();

export function focusStylePart(uuid: string, part: string) {
  listeners.forEach((listener) => listener(uuid, part));
}

/** Calls back when a part of this instance is chosen from the preview. */
export function useStylePartFocus(
  uuid: string | undefined,
  onFocus: (part: string) => void,
) {
  useEffect(() => {
    if (!uuid) return;
    const listener: Listener = (target, part) =>
      target === uuid && onFocus(part);
    listeners.add(listener);
    return () => {
      listeners.delete(listener);
    };
  }, [uuid, onFocus]);
}

/**
 * The named part under a click on a component's overlay, if any. Parts are
 * marked `data-canvas-style-part` in previews; the overlay sits above the
 * preview iframe, so the point is mapped into it, allowing for zoom.
 */
export function partAtPoint(
  roots: HTMLElement[],
  clientX: number,
  clientY: number,
): string | null {
  const document = roots[0]?.ownerDocument;
  const frame = document?.defaultView?.frameElement as
    HTMLElement | null | undefined;
  if (!document || !frame) return null;
  const rect = frame.getBoundingClientRect();
  const scale = frame.offsetWidth ? rect.width / frame.offsetWidth : 1;
  const hit = document.elementFromPoint(
    (clientX - rect.left) / scale,
    (clientY - rect.top) / scale,
  );
  const part = hit?.closest<HTMLElement>('[data-canvas-style-part]');
  if (!part || !roots.some((root) => root.contains(part))) return null;
  return part.dataset.canvasStylePart ?? null;
}
