import type { DiscoveredPage } from '@drupal-canvas/discovery';

function pageFallbackName(page: DiscoveredPage | undefined): string {
  return page?.relativePath || page?.slug || page?.name || 'unknown';
}

export function pageResultName(
  pageTitle: string | undefined,
  page: DiscoveredPage | undefined,
  options: { includePath?: boolean } = {},
): string {
  if (pageTitle) {
    return options.includePath && page?.relativePath
      ? `${pageTitle} (${page.relativePath})`
      : pageTitle;
  }
  return pageFallbackName(page);
}
