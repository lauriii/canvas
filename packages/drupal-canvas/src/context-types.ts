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

/** The context a rendering integration supplies to a component tree. */
export interface CanvasContext {
  page: PageContext | null;
  site: SiteContext | null;
}
