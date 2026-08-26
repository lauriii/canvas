/**
 * Recently used icons, remembered per browser via localStorage.
 *
 * Purely a per-user convenience: the list is not part of any saved content,
 * so storage failures (private windows, blocked site data) degrade to an
 * empty list.
 */

const STORAGE_KEY = 'canvas.iconPicker.recents';

/**
 * The maximum number of remembered icon ids.
 */
export const MAX_RECENT_ICONS = 8;

/**
 * Loads the remembered icon ids, most recent first.
 */
export function loadRecentIconIds(): string[] {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    return Array.isArray(parsed)
      ? parsed.filter((id): id is string => typeof id === 'string')
      : [];
  } catch {
    return [];
  }
}

/**
 * Records an icon id as the most recently used one.
 */
export function recordRecentIconId(id: string): void {
  try {
    const next = [id, ...loadRecentIconIds().filter((known) => known !== id)];
    window.localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify(next.slice(0, MAX_RECENT_ICONS)),
    );
  } catch {
    // Storage unavailable; the convenience is simply skipped.
  }
}
