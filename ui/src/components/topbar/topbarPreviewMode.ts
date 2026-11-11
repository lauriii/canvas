export const isPreviewPath = (pathname: string): boolean =>
  pathname.includes('/preview') || pathname.includes('/version-preview');

/**
 * Whether the route shows a page or template in another language than its
 * own, in the editor: the editor of one, with a `language` query parameter.
 */
export const isLanguageViewPath = (pathname: string, search: string): boolean =>
  new URLSearchParams(search).has('language') &&
  (/^\/editor\/[^/]+\/[^/]+/.test(pathname) ||
    /^\/template\/[^/]+\/[^/]+\/[^/]+\/[^/]+/.test(pathname));
