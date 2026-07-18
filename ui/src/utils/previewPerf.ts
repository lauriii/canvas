/**
 * Client-side performance marks for the preview pipeline.
 *
 * Together with the Server-Timing headers on the layout endpoints, these
 * attribute edit-to-paint latency to input debounce, server work, and client
 * apply. Inspect via performance.getEntriesByType('mark') filtered on the
 * `canvas:preview:` prefix.
 */
const PREFIX = 'canvas:preview:';

export const previewPerfMark = (
  name: string,
  detail?: Record<string, unknown>,
): void => {
  try {
    performance.mark(`${PREFIX}${name}`, detail ? { detail } : undefined);
  } catch {
    // Instrumentation must never break the editor.
  }
};
