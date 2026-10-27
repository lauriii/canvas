import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { formatDateInLocale } from './date-utils';

import type { CanvasDateFormatOptions } from './date-utils';

/** Formats in the `en` locale, which most cases below assert against. */
const formatDate = (
  value: string | null | undefined,
  options?: CanvasDateFormatOptions,
): string => formatDateInLocale(value, 'en', options);

describe('formatDateInLocale with a date value', () => {
  it('returns a formatted date string for a valid ISO date (en)', () => {
    const result = formatDate('2026-01-15');
    // Short date format contains digits and a separator — not the raw ISO.
    expect(result).toMatch(/\d+[/\-.]\d+/);
    expect(result).not.toBe('2026-01-15');
  });

  it('returns the original string for an empty input', () => {
    expect(formatDate('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(formatDate('not-a-date')).toBe('not-a-date');
  });

  it('does not shift a date-only value to the previous day', () => {
    // ECMAScript parses date-only strings (YYYY-MM-DD) as UTC midnight.
    // Without timeZone: 'UTC', Intl.DateTimeFormat renders in local time,
    // which shifts midnight UTC to the previous evening in negative-offset
    // timezones (e.g. Americas), producing the wrong date.
    // The `en` short date style is M/D/YY.
    expect(formatDate('2026-09-08')).toBe('9/8/26');
  });

  it('formats differently for fr locale', () => {
    const result = formatDateInLocale('2026-01-15', 'fr');
    expect(result).toBe('15/01/2026');
  });

  it('formats for ja locale without throwing', () => {
    const result = formatDateInLocale('2026-01-15', 'ja');
    expect(result).not.toBe('2026-01-15');
    expect(result).not.toBe('');
  });

  it('falls back gracefully for an invalid locale string', () => {
    // Should not throw; falls back to 'en' internally.
    expect(formatDateInLocale('2026-01-15', 'invalid-LOCALE-999')).toBe(
      '1/15/26',
    );
  });

  it('accepts a dateStyle option that overrides the default short style', () => {
    // With dateStyle: 'long' the year is written in full and the month is
    // spelled out — the result is longer than the short format.
    const shortResult = formatDate('2026-01-15');
    const longResult = formatDate('2026-01-15', { dateStyle: 'long' });
    expect(longResult.length).toBeGreaterThan(shortResult.length);
    expect(longResult).not.toBe('2026-01-15');
  });

  it('CanvasDateFormatOptions type is satisfied by a plain object', () => {
    // Type-level check: the options parameter accepts CanvasDateFormatOptions.
    const opts: CanvasDateFormatOptions = { dateStyle: 'medium' };
    const result = formatDate('2026-01-15', opts);
    expect(typeof result).toBe('string');
  });
});

describe('formatDateInLocale with a date-time value', () => {
  it('returns a formatted datetime string for a valid ISO datetime', () => {
    const result = formatDate('2026-01-15T14:30:00Z');
    expect(result).not.toBe('2026-01-15T14:30:00Z');
    expect(result).not.toBe('');
  });

  it('does not shift the time by the local UTC offset', () => {
    // Canvas datetime props are stored in UTC without timezone awareness.
    // Formatting must not apply a local-timezone offset — e.g. a value stored
    // as 23:00 UTC must never appear as 03:00 the next day in an ET browser.
    // timeZone: 'UTC' in the formatter guarantees this.
    const result = formatDate('2026-09-08T23:00:00Z');
    // The date portion must always be Sept 8, never Sept 9.
    expect(result).toMatch(/9\/8/);
    expect(result).not.toMatch(/9\/9/);
  });

  it('returns the original string for an empty input', () => {
    expect(formatDate('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(formatDate('not-a-date')).toBe('not-a-date');
  });

  it('accepts dateStyle and timeStyle options that override the defaults', () => {
    const shortResult = formatDate('2026-01-15T14:30:00Z');
    const longResult = formatDate('2026-01-15T14:30:00Z', {
      dateStyle: 'long',
      timeStyle: 'medium',
    });
    expect(longResult.length).toBeGreaterThan(shortResult.length);
    expect(longResult).not.toBe('2026-01-15T14:30:00Z');
  });
});

describe('formatDateInLocale with a time value', () => {
  it('returns a formatted time string for a bare HH:MM:SS time', () => {
    const result = formatDate('14:30:00');
    expect(result).not.toBe('14:30:00');
    expect(result).not.toBe('');
    // Short time format contains digits and a colon.
    expect(result).toMatch(/\d+:\d+/);
  });

  it('returns the original string for an empty input', () => {
    expect(formatDate('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(formatDate('not-a-time')).toBe('not-a-time');
  });

  it('accepts a timeStyle option that overrides the default short style', () => {
    const shortResult = formatDate('14:30:00');
    const mediumResult = formatDate('14:30:00', { timeStyle: 'medium' });
    // medium includes seconds; it must be at least as long as short.
    expect(mediumResult.length).toBeGreaterThanOrEqual(shortResult.length);
    expect(mediumResult).not.toBe('14:30:00');
  });
});

describe('optional props without a value', () => {
  it.each([null, undefined])('returns an empty string for %s', (value) => {
    expect(formatDate(value)).toBe('');
  });
});

describe('values without a UTC offset in a non-UTC timezone', () => {
  // Node re-reads process.env.TZ on assignment, so the tests below run in
  // Eastern Time (UTC-5 in January) regardless of the machine's timezone.
  const originalTz = process.env.TZ;
  beforeEach(() => {
    process.env.TZ = 'America/New_York';
  });
  afterEach(() => {
    if (originalTz === undefined) {
      delete process.env.TZ;
    } else {
      process.env.TZ = originalTz;
    }
  });

  const normalize = (value: string): string => value.replaceAll(' ', ' ');

  it('treats a datetime without an offset as UTC', () => {
    // Without the fix the value parses as 04:30 local, i.e. 09:30 UTC.
    expect(normalize(formatDate('2026-01-16T04:30:00'))).toBe(
      normalize(formatDate('2026-01-16T04:30:00Z')),
    );
    expect(normalize(formatDate('2026-01-16T04:30:00'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('treats a datetime with milliseconds and no offset as UTC', () => {
    expect(normalize(formatDate('2026-01-16T04:30:00.000'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('accepts a space-separated datetime and treats it as UTC', () => {
    expect(normalize(formatDate('2026-01-16 04:30:00'))).toBe(
      '1/16/26, 4:30 AM',
    );
    expect(normalize(formatDate('2026-01-16 04:30'))).toBe('1/16/26, 4:30 AM');
    expect(normalize(formatDate('2026-01-15 23:30:00-05:00'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('treats a bare time as UTC', () => {
    expect(normalize(formatDate('14:30:00'))).toBe('2:30 PM');
    expect(normalize(formatDate('14:30'))).toBe('2:30 PM');
  });

  it('converts a datetime with an explicit non-UTC offset to UTC', () => {
    expect(normalize(formatDate('2026-01-15T23:30:00-05:00'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('does not shift a date-only value', () => {
    expect(formatDate('2026-01-16')).toBe('1/16/26');
  });
});

describe('formatDateInLocale part selection', () => {
  const normalize = (value: string): string => value.replaceAll(' ', ' ');

  it.each([
    ['2026-01-15', undefined, '1/15/26'],
    ['14:30:00', undefined, '2:30 PM'],
    ['2026-01-15T14:30:00Z', undefined, '1/15/26, 2:30 PM'],
    ['2026-01-15', {}, '1/15/26'],
    ['14:30:00', {}, '2:30 PM'],
    ['2026-01-15T14:30:00Z', {}, '1/15/26, 2:30 PM'],
    [
      '2026-01-15T14:30:00Z',
      { dateStyle: undefined, timeStyle: undefined },
      '1/15/26, 2:30 PM',
    ],
    ['2026-01-15T14:30:00Z', { dateStyle: 'short' }, '1/15/26'],
    ['2026-01-15T14:30:00Z', { timeStyle: 'short' }, '2:30 PM'],
    [
      '2026-01-15',
      { dateStyle: 'long', timeStyle: 'short' },
      'January 15, 2026',
    ],
    ['14:30:00', { dateStyle: 'long', timeStyle: 'short' }, '2:30 PM'],
    ['2026-01-15', { timeStyle: 'short' }, '1/15/26'],
    ['14:30:00', { dateStyle: 'long' }, '2:30 PM'],
  ] satisfies [string, CanvasDateFormatOptions | undefined, string][])(
    'formats %s with %o as %s',
    (value, options, expected) => {
      expect(normalize(formatDate(value, options))).toBe(expected);
    },
  );
});
