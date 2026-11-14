/**
 * Counts edits made to the shown preview ahead of the server rendering them.
 *
 * A preview rendered from a request made before an edit doesn't show it, so
 * it would flash the state before the edit (see optimisticPreview.ts).
 */
let count = 0;

// The preview documents in which components were added, moved or removed
// since a render was last shown in them.
const layoutEdited = new WeakSet<Document>();

/**
 * Notes that the shown preview was edited.
 *
 * @param layoutOf
 *   The preview document, if the edit added, moved or removed a component.
 */
export const notePreviewEdit = (layoutOf?: Document) => {
  count++;
  if (layoutOf) {
    layoutEdited.add(layoutOf);
  }
};

/**
 * Whether components were added, moved or removed in a preview document since
 * a render was last shown in it. It may then have them elsewhere than that
 * render does.
 */
export const isPreviewLayoutEdited = (document: Document) =>
  layoutEdited.has(document);

/** Notes that a preview document shows a render, components included. */
export const notePreviewRendered = (document: Document) => {
  layoutEdited.delete(document);
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
