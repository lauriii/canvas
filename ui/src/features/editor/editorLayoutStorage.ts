/**
 * Persistence for the right (contextual) sidebar width in the editor layout.
 * Stored in pixels so the width stays constant when the left panel toggles.
 */

import { getLayoutItem, setLayoutItem } from '@/utils/layoutStorage';

export const EDITOR_SIDEBAR_LAYOUT_KEY = 'canvas-editor-sidebar-layout';

export const SIDEBAR_MIN_PX = 320;
export const SIDEBAR_MAX_PX = 640;
export const SIDEBAR_DEFAULT_PX = 320;

function isValidRightPx(value: unknown): value is number {
  return (
    typeof value === 'number' &&
    Number.isFinite(value) &&
    value >= SIDEBAR_MIN_PX &&
    value <= SIDEBAR_MAX_PX
  );
}

/**
 * Load stored right sidebar width in pixels.
 */
export function loadRightSidebarWidthPx(): number {
  const stored = getLayoutItem(EDITOR_SIDEBAR_LAYOUT_KEY);
  if (!stored) return SIDEBAR_DEFAULT_PX;
  const value = Number(stored);
  return isValidRightPx(value) ? value : SIDEBAR_DEFAULT_PX;
}

export function saveRightSidebarWidthPx(px: number): void {
  const clamped = Math.max(
    SIDEBAR_MIN_PX,
    Math.min(SIDEBAR_MAX_PX, Math.round(px)),
  );
  setLayoutItem(EDITOR_SIDEBAR_LAYOUT_KEY, String(clamped));
}
