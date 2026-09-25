/**
 * @file
 * Deduplicated developer warnings for the context hooks. Each distinct
 * message is emitted once per JavaScript realm so a missing provider does not
 * flood the console on every render.
 */

const emitted = new Set<string>();

/** Emits `message` through `console.warn` once. */
export function warnOnce(message: string): void {
  if (emitted.has(message)) {
    return;
  }
  emitted.add(message);
  console.warn(`[drupal-canvas] ${message}`);
}

/** Test helper: forgets previously emitted warnings. */
export function resetWarnings(): void {
  emitted.clear();
}
