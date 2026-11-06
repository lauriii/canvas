// cspell:ignore über
export interface PageAnchor {
  // The URI fragment, including the leading `#`.
  value: string;
  // The suggestion text, such as `Section: #pricing`.
  label: string;
}

/**
 * Returns the document of the active editor preview iframe, if any.
 *
 * @see ui/src/features/layout/preview/IframeSwapper.tsx
 */
export const getPreviewDocument = (): Document | null =>
  document.querySelector<HTMLIFrameElement>(
    'iframe[data-canvas-swap-active="true"]',
  )?.contentDocument ?? null;

/**
 * Collects the element ids rendered by components in a preview document.
 *
 * Only elements inside component markup count; component root elements are
 * marked with `data-canvas-uuid`.
 *
 * @param previewDocument
 *   The preview document.
 * @param getComponentName
 *   Returns the display name of a component instance, given its UUID.
 *
 * @see ui/src/utils/function-utils.ts::mapCanvasDocument()
 */
export const collectPageAnchors = (
  previewDocument: Document | null,
  getComponentName: (uuid: string) => string,
): PageAnchor[] => {
  if (!previewDocument) {
    return [];
  }
  const seen = new Set<string>();
  const anchors: PageAnchor[] = [];
  previewDocument
    .querySelectorAll<HTMLElement>(
      '[data-canvas-uuid][id], [data-canvas-uuid] [id]',
    )
    .forEach((element) => {
      const { id } = element;
      // Skip Canvas's own ids, ids that can't be a URI fragment, and
      // duplicates (a fragment only ever targets the first match).
      if (!id || id.startsWith('canvas-') || /\s/.test(id) || seen.has(id)) {
        return;
      }
      seen.add(id);
      const uuid =
        element.closest<HTMLElement>('[data-canvas-uuid]')?.dataset
          .canvasUuid ?? '';
      const name = getComponentName(uuid);
      anchors.push({
        // Percent-encode the id, as ids such as `über-uns` are not valid in a
        // URI fragment. Browsers decode the fragment to find the element.
        value: `#${encodeURIComponent(id)}`,
        label: name ? `${name}: #${id}` : `#${id}`,
      });
    });
  return anchors;
};

/**
 * Filters anchors by what was typed: empty, `#`, or `#` and part of an id.
 *
 * Returns no anchors for any other input, so typing a page title or URL
 * searches as before.
 */
export const filterPageAnchors = (
  anchors: PageAnchor[],
  term: string,
): PageAnchor[] => {
  if (term !== '' && !term.startsWith('#')) {
    return [];
  }
  const needle = term.slice(1).toLowerCase();
  return anchors.filter(({ label }) => label.toLowerCase().includes(needle));
};
