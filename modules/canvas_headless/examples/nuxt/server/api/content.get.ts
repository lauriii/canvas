import { getClient } from '@drupal-canvas/headless-nuxt/server';

import type { Article, CanvasPage, ContentLists } from '#shared/content';

interface JsonApiDocument<T> {
  data?: T | null;
  errors?: Array<{ status?: string; detail?: string }>;
}

/**
 * The homepage's content lists, via JSON:API. Fetched in a server route
 * because the draft session lives in httpOnly request cookies: the client
 * is draft-session-aware and answers working copies while a session is
 * live.
 */
export default defineEventHandler(async (event): Promise<ContentLists> => {
  const client = await getClient(event);
  const [canvasPagesDocument, articlesDocument] = await Promise.all([
    client.getCollection('canvas_page--canvas_page') as Promise<
      JsonApiDocument<CanvasPage[]>
    >,
    client.getCollection('node--article') as Promise<
      JsonApiDocument<Article[]>
    >,
  ]);

  return {
    canvasPages: canvasPagesDocument?.data ?? [],
    articles: articlesDocument?.data ?? [],
  };
});
