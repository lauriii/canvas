/**
 * How far something must move to sit inside its container, and on screen.
 *
 * The on-canvas composer is anchored at the point that was clicked, so near an
 * edge it can hang outside the preview and end up behind the contextual panel,
 * which takes its submit button out of reach. Horizontal overflow is corrected
 * against the container; vertically it is allowed to sit above the preview,
 * over the canvas surround, and only has to stay on screen.
 *
 * @param rect - Where the element currently is.
 * @param bounds - The container it must stay within horizontally.
 * @returns The x and y to add to its position.
 */
export const clampIntoBounds = (
  rect: { top: number; left: number; right: number },
  bounds: { left: number; right: number },
): { x: number; y: number } => {
  let x = 0;
  if (rect.right > bounds.right) {
    x = bounds.right - rect.right;
  }
  // A container narrower than the element cannot fit it; align left rather
  // than pushing it off the other side.
  if (rect.left + x < bounds.left) {
    x = bounds.left - rect.left;
  }
  return { x, y: rect.top < 0 ? -rect.top : 0 };
};
