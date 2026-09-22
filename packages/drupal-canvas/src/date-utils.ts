/**
 * Locale-aware date formatting utilities for Canvas Code Components.
 *
 * These functions format ISO date/time strings using the Drupal-selected
 * langcode from `drupalSettings.canvasData.v0.langcode`. They are opt-in: Canvas passes date
 * props to templates as raw ISO strings by default. Use these utilities when
 * you want to display a date in the user's locale.
 *
 * All functions accept an optional `options` object so callers can override
 * default format styles without losing the Drupal langcode or the UTC timezone
 * guard that prevents stored dates from shifting by the viewer's UTC offset.
 *
 * @example Twig: `{{ my_date_prop|canvasFormatDate }}`
 * @example JSX: `canvasFormatDate(myDateProp)`
 * @example JSX with options: `canvasFormatDate(myDateProp, { dateStyle: 'long' })`
 */

/**
 * Options accepted by the Canvas date formatting utilities.
 *
 * All fields are optional and default to `'short'`. Pass an options object to
 * override the format style without losing the Drupal langcode or UTC timezone
 * guard.
 */
export interface CanvasDateFormatOptions {
  /** Controls the date portion style. Defaults to `'short'`. */
  dateStyle?: Intl.DateTimeFormatOptions['dateStyle'];
  /** Controls the time portion style. Defaults to `'short'`. */
  timeStyle?: Intl.DateTimeFormatOptions['timeStyle'];
}

/**
 * Reads the Drupal-selected langcode from Canvas runtime settings when present,
 * falling back to `drupalSettings.langcode` and then 'en'.
 *
 * Canvas attaches `canvasData.v0.langcode` for every code component that imports
 * one of these utilities: the import adds `v0.langcode` to the component's
 * `dataDependencies.drupalSettings`. Drupal core only sets
 * `drupalSettings.langcode` on pages with a machine name form element, so that
 * fallback is not a langcode source on rendered pages.
 *
 * @see ui/src/features/code-editor/utils/ast-utils.ts
 */
const getDrupalLangcode = (): string =>
  (
    globalThis as {
      drupalSettings?: {
        canvasData?: { v0?: { langcode?: string } };
        langcode?: string;
      };
    }
  ).drupalSettings?.canvasData?.v0?.langcode ??
  (globalThis as { drupalSettings?: { langcode?: string } }).drupalSettings
    ?.langcode ??
  'en';

/**
 * Creates an `Intl.DateTimeFormat` instance, falling back to 'en' locale if
 * the Drupal langcode string is not a valid BCP 47 tag.
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
 * whereas date-only strings are parsed as UTC. Canvas treats every stored
 * value as UTC (the PHP side appends `Z` before formatting), so a `Z` is
 * appended to date-time strings that carry no offset. Without it, the UTC
 * formatter would shift the value by the viewer's UTC offset.
 */
const parseIso = (iso: string): Date | null => {
  if (!iso) return null;
  const hasTimePart = /[T ]\d{2}:\d{2}/.test(iso);
  const hasOffset = /(Z|[+-]\d{2}(:?\d{2})?)$/i.test(iso);
  const d = new Date(hasTimePart && !hasOffset ? `${iso}Z` : iso);
  return isNaN(d.getTime()) ? null : d;
};

/**
 * Formats an ISO date string using the active Drupal locale's date format.
 *
 * Returns the original `iso` string unchanged if it cannot be parsed, and an
 * empty string for `null` or `undefined` (an optional prop without a value).
 *
 * @param iso
 *   ISO date string, e.g. '2026-01-15'.
 * @param options
 *   Optional format overrides. Defaults to `{ dateStyle: 'short' }`.
 *
 * @return
 *   Locale-formatted date string, e.g. '1/15/26' (en) or '15/01/2026' (fr).
 */
export function canvasFormatDate(
  iso: string | null | undefined,
  options?: Pick<CanvasDateFormatOptions, 'dateStyle'>,
): string {
  if (iso == null) return '';
  const date = parseIso(iso);
  if (!date) return iso;
  return createFormatter(getDrupalLangcode(), {
    dateStyle: options?.dateStyle ?? 'short',
  }).format(date);
}

/**
 * Formats an ISO datetime string using the active Drupal locale's format.
 *
 * Returns the original `iso` string unchanged if it cannot be parsed, and an
 * empty string for `null` or `undefined` (an optional prop without a value).
 *
 * @param iso
 *   ISO datetime string, e.g. '2026-01-15T14:30:00Z'.
 * @param options
 *   Optional format overrides. Defaults to
 *   `{ dateStyle: 'short', timeStyle: 'short' }`.
 *
 * @return
 *   Locale-formatted datetime string.
 */
export function canvasFormatDateTime(
  iso: string | null | undefined,
  options?: CanvasDateFormatOptions,
): string {
  if (iso == null) return '';
  const date = parseIso(iso);
  if (!date) return iso;
  return createFormatter(getDrupalLangcode(), {
    dateStyle: options?.dateStyle ?? 'short',
    timeStyle: options?.timeStyle ?? 'short',
  }).format(date);
}

/**
 * Formats an ISO time/datetime string using the active Drupal locale's time format.
 *
 * Returns the original `iso` string unchanged if it cannot be parsed, and an
 * empty string for `null` or `undefined` (an optional prop without a value).
 *
 * @param iso
 *   ISO time or datetime string, e.g. '14:30:00' or '2026-01-15T14:30:00Z'.
 * @param options
 *   Optional format overrides. Defaults to `{ timeStyle: 'short' }`.
 *
 * @return
 *   Locale-formatted time string.
 */
export function canvasFormatTime(
  iso: string | null | undefined,
  options?: Pick<CanvasDateFormatOptions, 'timeStyle'>,
): string {
  if (iso == null) return '';
  // For bare time strings like 'HH:MM:SS', prefix a dummy date so Date() can parse them.
  const normalized = /^\d{2}:\d{2}/.test(iso) ? `1970-01-01T${iso}` : iso;
  const date = parseIso(normalized);
  if (!date) return iso;
  return createFormatter(getDrupalLangcode(), {
    timeStyle: options?.timeStyle ?? 'short',
  }).format(date);
}

/**
 * Formats the `from` and `to` fields of a Canvas date-range prop.
 *
 * Returns `null` or `undefined` unchanged (an optional prop without a value).
 *
 * @param range
 *   Canvas date-range object, e.g. `{ from: '2026-01-15', to: '2026-03-20' }`.
 * @param options
 *   Optional format overrides passed to each field. Defaults to
 *   `{ dateStyle: 'short' }`.
 *
 * @return
 *   A new object with the same keys; each string date value is replaced with a
 *   formatted string.
 */
export function canvasFormatDateRange<
  T extends { from?: string | null; to?: string | null } | null | undefined,
>(range: T, options?: Pick<CanvasDateFormatOptions, 'dateStyle'>): T {
  if (range == null) return range;
  const result: NonNullable<T> = { ...range };
  if (typeof result.from === 'string') {
    result.from = canvasFormatDate(result.from, options) as typeof result.from;
  }
  if (typeof result.to === 'string') {
    result.to = canvasFormatDate(result.to, options) as typeof result.to;
  }
  return result;
}
