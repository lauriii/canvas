/**
 * Locale-aware date formatting for Canvas Code Components.
 *
 * `useDateFormatter()` (see `date-formatter.ts`) returns a function that
 * formats ISO date/time strings in the interface language the rendering
 * integration supplies through the site context. This module holds the
 * React-free formatting itself. Formatting is opt-in: Canvas passes date props
 * to components as raw ISO strings by default.
 *
 * It accepts an optional `options` object so callers can override
 * default format styles without losing the site locale or the UTC timezone
 * guard that prevents stored dates from shifting by the viewer's UTC offset.
 */

/**
 * Options accepted by the function `useDateFormatter()` returns.
 *
 * Pass an options object to override the format style without losing the
 * site locale or UTC timezone guard. When neither style is set, each part
 * the value has is shown in the `'short'` style. When at least one is set,
 * only the parts set are shown. A part the value does not have is ignored.
 */
export interface CanvasDateFormatOptions {
  /** Style of the date part. Defaults to `'short'` when no option is set. */
  dateStyle?: Intl.DateTimeFormatOptions['dateStyle'];
  /** Style of the time part. Defaults to `'short'` when no option is set. */
  timeStyle?: Intl.DateTimeFormatOptions['timeStyle'];
}

/**
 * Creates an `Intl.DateTimeFormat` instance, falling back to 'en' locale if
 * the langcode string is not a valid BCP 47 tag.
 *
 * Drupal langcodes like `zh-hans` or `pt-br` use lowercase language tag
 * components which may throw `RangeError` in strict BCP 47 environments.
 * The try/catch ensures graceful degradation.
 *
 * All formatters use `timeZone: 'UTC'` because Canvas date/datetime props are
 * stored without timezone offset. Formatting in the local browser timezone
 * would silently shift dates — e.g. a UTC midnight date would appear as the
 * previous day in negative-UTC-offset timezones (Americas).
 */
const createFormatter = (
  locale: string,
  options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat => {
  const utcOptions = { timeZone: 'UTC', ...options };
  try {
    return new Intl.DateTimeFormat(locale, utcOptions);
  } catch {
    return new Intl.DateTimeFormat('en', utcOptions);
  }
};

/**
 * Parses an ISO string to a Date object, returning null on failure.
 *
 * ECMAScript parses a date-time string without a UTC offset as local time,
 * whereas date-only strings are parsed as UTC. Canvas treats every value as
 * UTC, but values can arrive without an offset (e.g. static prop values, which
 * never pass through the PHP evaluator). So a `Z` is appended to date-time
 * strings that carry no offset, regardless of what the server did. Without it,
 * the UTC formatter would shift the value by the viewer's UTC offset.
 */
const parseIso = (iso: string): Date | null => {
  if (!iso) return null;
  // Only the `T` separator is defined by ECMAScript; the space form is a
  // non-standard fallback that some engines (e.g. Safari) reject.
  const normalized = iso.replace(/^(\d{4}-\d{2}-\d{2}) (?=\d{2}:\d{2})/, '$1T');
  const hasTimePart = /[T ]\d{2}:\d{2}/.test(normalized);
  const hasOffset = /(?:Z|[+-]\d{2}(?::?\d{2})?)$/i.test(normalized);
  const d = new Date(hasTimePart && !hasOffset ? `${normalized}Z` : normalized);
  return isNaN(d.getTime()) ? null : d;
};

/**
 * Formats an ISO date, date-time, or time string in a locale.
 *
 * The parts shown depend on the value:
 * - date only (`2026-01-15`): the date.
 * - time only (`14:30:00`): the time.
 * - date and time (`2026-01-15T14:30:00Z`): the date and the time.
 *
 * Each part uses the `'short'` style unless `options` is passed. When
 * `options` is passed, only the parts it sets are shown, so
 * `{ timeStyle: 'short' }` shows only the time of a date-time value. A part the
 * value does not have is ignored, e.g. `timeStyle` for a date-only value. If
 * no part remains, the defaults for the value apply.
 *
 * Returns the original `value` string unchanged if it cannot be parsed, and an
 * empty string for `null` or `undefined` (an optional prop without a value).
 *
 * To format a date range prop, call this function on its `from` and `to`
 * values.
 *
 * @param value
 *   ISO date, date-time, or time string.
 * @param locale
 *   The Drupal langcode of the locale, e.g. `fr`.
 * @param options
 *   Optional format styles for the date and time parts.
 *
 * @return
 *   Locale-formatted string, e.g. '1/15/26' (en) or '15/01/2026' (fr) for
 *   '2026-01-15'.
 */
export function formatDateInLocale(
  value: string | null | undefined,
  locale: string,
  options?: CanvasDateFormatOptions,
): string {
  if (value == null) return '';
  const isTimeOnly = /^\d{2}:\d{2}/.test(value);
  const hasDate = !isTimeOnly;
  const hasTime = isTimeOnly || /[T ]\d{2}:\d{2}/.test(value);
  // Prefix a bare time like 'HH:MM:SS' with a dummy date so Date() can parse
  // it.
  const date = parseIso(isTimeOnly ? `1970-01-01T${value}` : value);
  if (!date) return value;

  const dateStyle = hasDate ? options?.dateStyle : undefined;
  const timeStyle = hasTime ? options?.timeStyle : undefined;
  const formatOptions: Intl.DateTimeFormatOptions =
    dateStyle || timeStyle
      ? { dateStyle, timeStyle }
      : {
          dateStyle: hasDate ? 'short' : undefined,
          timeStyle: hasTime ? 'short' : undefined,
        };
  return createFormatter(locale, formatOptions).format(date);
}

/** Formats ISO date, date-time, and time strings in the site's locale. */
export type DateFormatter = (
  value: string | null | undefined,
  options?: CanvasDateFormatOptions,
) => string;
