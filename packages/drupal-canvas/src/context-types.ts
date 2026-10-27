/**
 * @file
 * The React-free data shapes of the context API, shared with server-side
 * code (the Headless SDK's page response) that must not load React.
 */

import type { PageData, SiteData } from './drupal-utils.js';

/**
 * The page context: the same shape `getPageData()` returns. The primary
 * entity can be `null` on routes without one.
 */
export type PageContext = PageData;

/**
 * The site context: the same shape `getSiteData()` returns. Headless
 * frontends receive empty theme asset URLs; that is valid site context, not
 * missing context.
 */
export type SiteContext = SiteData;

/**
 * Interface translations for the strings Code Components pass to `t()` and
 * `formatPlural()`, in the page's interface language. Same layout as Drupal's
 * `drupalTranslations`.
 */
export interface Translations {
  /**
   * Translated strings keyed by context (`''` when none), then by source
   * string. Plural strings join their forms with `\x03`, in source and
   * translation alike. Untranslated strings can be omitted.
   */
  strings: Record<string, Record<string, string>>;
  /**
   * The plural form index for a count, with a `default` for counts not
   * listed. Without it, translated plurals use the first form for 1 and the
   * second for other counts.
   */
  pluralFormula?: Record<string, number> | null;
}

/** The context a rendering integration supplies to a component tree. */
export interface CanvasContext {
  page: PageContext | null;
  site: SiteContext | null;
  /**
   * Absent or `null` when there is nothing to translate: `t()` then returns
   * source strings.
   */
  translations?: Translations | null;
}
