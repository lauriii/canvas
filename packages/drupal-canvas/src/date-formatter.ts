import { useMemo } from 'react';

import { useSiteContext } from './context.js';
import { formatDateInLocale } from './date-utils.js';

import type { DateFormatter } from './date-utils.js';

const FALLBACK_LOCALE = 'en';

/**
 * Returns a function that formats ISO date, date-time, and time strings in the
 * interface language of the site.
 *
 * The locale comes from the site context, which every rendering integration
 * (Drupal-rendered pages, Workbench, Headless) supplies, so it needs no
 * runtime-specific setup. Without a site language it falls back to `en`.
 *
 * The parts shown depend on the value: a date, a time, or both. Each part uses
 * the `'short'` style unless options are passed; see `CanvasDateFormatOptions`.
 * Values are always formatted in UTC, because Canvas stores them without a
 * time zone. To format a date range prop, call the function on its `from` and
 * `to` values.
 *
 * @example
 * const formatDate = useDateFormatter();
 * return <time>{formatDate(date, { dateStyle: 'long' })}</time>;
 */
export function useDateFormatter(): DateFormatter {
  const langcode = useSiteContext()?.langcode || FALLBACK_LOCALE;
  return useMemo<DateFormatter>(
    () => (value, options) => formatDateInLocale(value, langcode, options),
    [langcode],
  );
}
