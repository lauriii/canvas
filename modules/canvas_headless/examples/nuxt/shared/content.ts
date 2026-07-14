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

/**
 * What the homepage's /api/content route answers.
 */
export interface ContentLists {
  canvasPages: CanvasPage[];
  articles: Article[];
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
