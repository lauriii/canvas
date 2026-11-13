/**
 * Counts edits made to the shown preview ahead of the server rendering them.
 *
 * A preview rendered from a request made before an edit doesn't show it, so
 * it would flash the state before the edit (see optimisticPreview.ts).
 */
let count = 0;

/** Notes that the shown preview was edited. */
export const notePreviewEdit = () => {
  count++;
};

/** The number of edits made to the shown preview so far. */
export const previewEditCount = () => count;

/**
 * Whether the shown preview was edited after a render was requested.
 *
 * @param editsAtRequest
 *   The edit count when the render was requested.
 */
export const isPreviewEditedSince = (editsAtRequest: number) =>
  count > editsAtRequest;
