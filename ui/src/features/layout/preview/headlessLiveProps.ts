import type { HeadlessPreviewHost } from '@drupal-canvas/headless-host';

/**
 * The hosts of the main headless previews.
 *
 * They show prop values in the frontend ahead of its next refresh, which
 * otherwise waits for the auto-save and then fetches the page again.
 */
export const mainPreviewHosts = new Set<HeadlessPreviewHost>();

/**
 * Whether the embedded frontend shows prop values ahead of its refresh.
 *
 * A prop form then sends every change of a scalar prop at once, without
 * debouncing.
 */
export const canShowLiveProps = () =>
  [...mainPreviewHosts].some((host) => host.canPatchProps());

/**
 * Makes the file URLs inside prop values absolute, as the content API does.
 *
 * The frontend runs on another origin, where a root-relative file URL of
 * Drupal does not resolve.
 */
// ponytail: knows the image and video shapes only (`src`, `poster`). Have the
// layout API return absolute file URLs if other shapes carry them.
const withAbsoluteFileUrls = (value: unknown, origin: string): unknown => {
  if (Array.isArray(value)) {
    return value.map((item) => withAbsoluteFileUrls(item, origin));
  }
  if (value === null || typeof value !== 'object') {
    return value;
  }
  return Object.fromEntries(
    Object.entries(value).map(([key, item]) => [
      key,
      (key === 'src' || key === 'poster') &&
      typeof item === 'string' &&
      /^\/(?!\/)/.test(item)
        ? origin +
          item.replace(
            'alternateWidths=/',
            `alternateWidths=${encodeURIComponent(origin)}/`,
          )
        : withAbsoluteFileUrls(item, origin),
    ]),
  );
};

/**
 * Shows the props of a component as an auto-save resolved them.
 *
 * This covers what only Drupal can resolve, such as media, links and
 * formatted text, without waiting for the frontend to fetch the page again.
 *
 * @param componentUuid
 *   The component instance.
 * @param resolved
 *   Its resolved props in the auto-save response.
 * @param editsAtRequest
 *   The count of typed edits when the auto-save was requested.
 */
export const showSavedProps = (
  componentUuid: string,
  resolved: Record<string, unknown> | undefined,
  editsAtRequest: number,
) => {
  if (!resolved) {
    return;
  }
  const props = Object.fromEntries(
    Object.entries(resolved).map(([name, value]) => [
      name,
      withAbsoluteFileUrls(value, window.location.origin),
    ]),
  );
  mainPreviewHosts.forEach((host) =>
    host.patchProps(componentUuid, props, editsAtRequest),
  );
};
