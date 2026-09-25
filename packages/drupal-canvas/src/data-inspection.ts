/**
 * @file
 * Editor data inspection: reports the values a Code Component reads through
 * the context hooks to the Canvas code editor's "Component data" panel, using
 * the same `_canvas_useswr_data_fetch` message the legacy getters emit. The
 * reports never fetch data or refresh previews; they are deduplicated per
 * hook and value so repeated renders do not repeat entries.
 */

const lastReported = new Map<string, string>();

/**
 * Posts one deduplicated data report to the embedding document, when there
 * is one. Standalone documents (no parent frame) and server rendering are
 * no-ops.
 */
export function reportCanvasData(id: string, data: unknown): void {
  if (typeof window === 'undefined') {
    return;
  }
  const parent = window.parent;
  if (!parent || parent === window) {
    return;
  }
  let serialized: string;
  try {
    serialized = JSON.stringify(data) ?? 'undefined';
  } catch {
    return;
  }
  if (lastReported.get(id) === serialized) {
    return;
  }
  lastReported.set(id, serialized);
  try {
    parent.postMessage({ type: '_canvas_useswr_data_fetch', id, data });
  } catch {
    // The embedding document may not accept messages; inspection is optional.
  }
}

/** Test helper: forgets previously reported values. */
export function resetCanvasDataReports(): void {
  lastReported.clear();
}
