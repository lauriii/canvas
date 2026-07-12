import {
  getDraftClient,
  getDraftData,
  getPublicClient,
  isDraftSessionExpired,
} from "@/lib/drupal-draft";

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
 * Returns the right client for the current request: the draft client
 * (user-bound session token, working copies) while the draft session is
 * live, otherwise the public client (unauthenticated, published content
 * only). An expired draft session falls back to anonymous fetching — the
 * draft indicator banner makes that state visible instead of letting
 * anonymous-visible content masquerade as a draft.
 */
async function getClient() {
  const draftData = await getDraftData();
  return draftData && !isDraftSessionExpired(draftData)
    ? getDraftClient(draftData)
    : getPublicClient();
}

/**
 * Fetches the article list for the homepage, via JSON:API.
 */
export async function getArticles(): Promise<Article[]> {
  const client = await getClient();
  const document = (await client.getCollection(
    "node--article",
  )) as JsonApiDocument<Article[]>;
  return document?.data ?? [];
}

/**
 * Fetches the Canvas page list for the homepage, via JSON:API.
 */
export async function getCanvasPages(): Promise<CanvasPage[]> {
  const client = await getClient();
  const document = (await client.getCollection(
    "canvas_page--canvas_page",
  )) as JsonApiDocument<CanvasPage[]>;
  return document?.data ?? [];
}

/**
 * The app-side path a Canvas page is served at: its alias when it has one,
 * its canonical Drupal path otherwise. Both resolve through the catch-all
 * route, which hands them to the CE API — Drupal's own routing does the
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
 * land in the catch-all route and render through the CE API.
 */
export function articlePath(article: Article): string {
  return (
    article.attributes.path?.alias ||
    `/node/${article.attributes.drupal_internal__nid}`
  );
}
