/**
 * localStorage helpers for layout persistence.
 * Catches storage errors so callers can safely fall back to defaults.
 */

export function getLayoutItem(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

export function setLayoutItem(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    // Ignore storage errors.
  }
}
