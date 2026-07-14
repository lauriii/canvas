import { getClient } from '@drupal-canvas/headless-astro';

import type { AstroDraftContext } from '@drupal-canvas/headless-astro';

export interface Article {
  id: string;
  attributes: {
    title: string;
    status: boolean;
    moderation_state?: string;
    drupal_internal__nid: number;
    path?: { alias?: string | null } | null;
  };
}

export interface CanvasPage {
  id: string;
  attributes: {
    title: string;
    status: boolean;
    drupal_internal__id: number;
    path?: { alias?: string | null } | null;
  };
}

interface JsonApiDocument<T> {
  data?: T | null;
  errors?: Array<{ status?: string; detail?: string }>;
}

/**
 * Fetches the article list for the homepage, via JSON:API. Takes the
 * request's `Astro` global: the client is draft-session-aware, and in
 * Astro the session travels with the request context rather than a
 * request-scoped global.
 */
export async function getArticles(
  context: AstroDraftContext,
): Promise<Article[]> {
  const client = await getClient(context);
  const document = (await client.getCollection(
    'node--article',
  )) as JsonApiDocument<Article[]>;
  return document?.data ?? [];
}

/**
 * Fetches the Canvas page list for the homepage, via JSON:API.
 */
export async function getCanvasPages(
  context: AstroDraftContext,
): Promise<CanvasPage[]> {
  const client = await getClient(context);
  const document = (await client.getCollection(
    'canvas_page--canvas_page',
  )) as JsonApiDocument<CanvasPage[]>;
  return document?.data ?? [];
}

/**
 * The app-side path a Canvas page is served at: its alias when it has one,
 * its canonical Drupal path otherwise. Both resolve through the catch-all
 * route, which hands them to fetchPage() — Drupal's own routing does the
 * rest.
 */
export function canvasPagePath(page: CanvasPage): string {
  return (
    page.attributes.path?.alias || `/page/${page.attributes.drupal_internal__id}`
  );
}

/**
 * The app-side path an article is served at, resolved the same way as
 * Canvas pages: alias when present, canonical Drupal path otherwise. Both
 * land in the catch-all route and render through fetchPage().
 */
export function articlePath(article: Article): string {
  return (
    article.attributes.path?.alias ||
    `/node/${article.attributes.drupal_internal__nid}`
  );
}
