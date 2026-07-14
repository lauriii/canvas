import { fetchPage } from '@drupal-canvas/headless-nuxt/server';

/**
 * Resolves a Drupal path through Drupal's routing (the SDK's fetchPage()),
 * carrying the live draft session's bearer token when there is one. The catch-all page
 * consumes this; a missing page answers 404 with a null body so the page
 * can render its own not-found state.
 */
export default defineEventHandler(async (event) => {
  const rawPath = getRouterParam(event, 'path') ?? '';
  const path = `/${rawPath.split('/').map(encodeURIComponent).join('/')}`;
  const page = await fetchPage(event, path);

  if (!page) {
    setResponseStatus(event, 404);
    return null;
  }
  return page;
});
