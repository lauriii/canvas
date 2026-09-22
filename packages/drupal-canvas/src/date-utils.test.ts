import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  canvasFormatDate,
  canvasFormatDateRange,
  canvasFormatDateTime,
  canvasFormatTime,
} from './date-utils';

import type { CanvasDateFormatOptions } from './date-utils';

/** Helper to set langcode settings for the duration of a test. */
function setLangcode(
  langcode: string,
  canvasLangcode: string | null = langcode,
): void {
  (
    globalThis as {
      drupalSettings?: {
        canvasData?: { v0?: { langcode?: string | null } };
        langcode?: string;
      };
    }
  ).drupalSettings = {
    langcode,
    canvasData: {
      v0: {
        langcode: canvasLangcode,
      },
    },
  };
}

/** Helper to clear drupalSettings after each test. */
function clearLocale(): void {
  delete (globalThis as { drupalSettings?: unknown }).drupalSettings;
}

describe('canvasFormatDate', () => {
  beforeEach(() => setLangcode('en'));
  afterEach(() => clearLocale());

  it('returns a formatted date string for a valid ISO date (en)', () => {
    const result = canvasFormatDate('2026-01-15');
    // Short date format contains digits and a separator — not the raw ISO.
    expect(result).toMatch(/\d+[/\-.]\d+/);
    expect(result).not.toBe('2026-01-15');
  });

  it('returns the original string for an empty input', () => {
    expect(canvasFormatDate('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(canvasFormatDate('not-a-date')).toBe('not-a-date');
  });

  it('does not shift a date-only value to the previous day', () => {
    // ECMAScript parses date-only strings (YYYY-MM-DD) as UTC midnight.
    // Without timeZone: 'UTC', Intl.DateTimeFormat renders in local time,
    // which shifts midnight UTC to the previous evening in negative-offset
    // timezones (e.g. Americas), producing the wrong date.
    const result = canvasFormatDate('2026-09-08');
    expect(result).toMatch(/9\/8/);
    expect(result).not.toMatch(/9\/7/);
  });

  it('formats differently for fr locale', () => {
    setLangcode('fr');
    const result = canvasFormatDate('2026-01-15');
    expect(result).not.toBe('2026-01-15');
    expect(result).not.toBe('');
  });

  it('formats for ja locale without throwing', () => {
    setLangcode('ja');
    const result = canvasFormatDate('2026-01-15');
    expect(result).not.toBe('2026-01-15');
    expect(result).not.toBe('');
  });

  it('falls back gracefully for an invalid langcode string', () => {
    setLangcode('invalid-LOCALE-999');
    // Should not throw; falls back to 'en' internally.
    const result = canvasFormatDate('2026-01-15');
    expect(typeof result).toBe('string');
    expect(result).not.toBe('');
  });

  it('uses "en" fallback when drupalSettings is absent', () => {
    clearLocale();
    const result = canvasFormatDate('2026-01-15');
    expect(result).toMatch(/\d+[/\-.]\d+/);
    expect(result).not.toBe('2026-01-15');
  });

  it('prefers canvasData.v0.langcode over drupalSettings.langcode', () => {
    setLangcode('en', 'fr');
    const frenchResult = canvasFormatDate('2026-01-15');
    setLangcode('en', 'en');
    const englishResult = canvasFormatDate('2026-01-15');
    expect(frenchResult).not.toBe('2026-01-15');
    expect(englishResult).not.toBe('2026-01-15');
    expect(frenchResult).not.toBe(englishResult);
  });

  it('accepts a dateStyle option that overrides the default short style', () => {
    // With dateStyle: 'long' the year is written in full and the month is
    // spelled out — the result is longer than the short format.
    const shortResult = canvasFormatDate('2026-01-15');
    const longResult = canvasFormatDate('2026-01-15', { dateStyle: 'long' });
    expect(longResult.length).toBeGreaterThan(shortResult.length);
    expect(longResult).not.toBe('2026-01-15');
  });

  it('CanvasDateFormatOptions type is satisfied by a plain object', () => {
    // Type-level check: the options parameter accepts CanvasDateFormatOptions.
    const opts: CanvasDateFormatOptions = { dateStyle: 'medium' };
    const result = canvasFormatDate('2026-01-15', opts);
    expect(typeof result).toBe('string');
  });
});

describe('canvasFormatDateTime', () => {
  beforeEach(() => setLangcode('en'));
  afterEach(() => clearLocale());

  it('returns a formatted datetime string for a valid ISO datetime', () => {
    const result = canvasFormatDateTime('2026-01-15T14:30:00Z');
    expect(result).not.toBe('2026-01-15T14:30:00Z');
    expect(result).not.toBe('');
  });

  it('does not shift the time by the local UTC offset', () => {
    // Canvas datetime props are stored in UTC without timezone awareness.
    // Formatting must not apply a local-timezone offset — e.g. a value stored
    // as 23:00 UTC must never appear as 03:00 the next day in an ET browser.
    // timeZone: 'UTC' in the formatter guarantees this.
    const result = canvasFormatDateTime('2026-09-08T23:00:00Z');
    // The date portion must always be Sept 8, never Sept 9.
    expect(result).toMatch(/9\/8/);
    expect(result).not.toMatch(/9\/9/);
  });

  it('returns the original string for an empty input', () => {
    expect(canvasFormatDateTime('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(canvasFormatDateTime('not-a-date')).toBe('not-a-date');
  });

  it('accepts dateStyle and timeStyle options that override the defaults', () => {
    const shortResult = canvasFormatDateTime('2026-01-15T14:30:00Z');
    const longResult = canvasFormatDateTime('2026-01-15T14:30:00Z', {
      dateStyle: 'long',
      timeStyle: 'medium',
    });
    expect(longResult.length).toBeGreaterThan(shortResult.length);
    expect(longResult).not.toBe('2026-01-15T14:30:00Z');
  });
});

describe('canvasFormatTime', () => {
  beforeEach(() => setLangcode('en'));
  afterEach(() => clearLocale());

  it('returns a formatted time string for a bare HH:MM:SS time', () => {
    const result = canvasFormatTime('14:30:00');
    expect(result).not.toBe('14:30:00');
    expect(result).not.toBe('');
    // Short time format contains digits and a colon.
    expect(result).toMatch(/\d+:\d+/);
  });

  it('returns a formatted time for a full ISO datetime string', () => {
    const result = canvasFormatTime('2026-01-15T14:30:00Z');
    expect(result).not.toBe('2026-01-15T14:30:00Z');
    expect(result).not.toBe('');
  });

  it('returns the original string for an empty input', () => {
    expect(canvasFormatTime('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(canvasFormatTime('not-a-time')).toBe('not-a-time');
  });

  it('accepts a timeStyle option that overrides the default short style', () => {
    const shortResult = canvasFormatTime('14:30:00');
    const mediumResult = canvasFormatTime('14:30:00', { timeStyle: 'medium' });
    // medium includes seconds; it must be at least as long as short.
    expect(mediumResult.length).toBeGreaterThanOrEqual(shortResult.length);
    expect(mediumResult).not.toBe('14:30:00');
  });
});

describe('optional props without a value', () => {
  beforeEach(() => setLangcode('en'));
  afterEach(() => clearLocale());

  it.each([null, undefined])(
    'returns an empty string for %s in the string formatters',
    (value) => {
      expect(canvasFormatDate(value)).toBe('');
      expect(canvasFormatDateTime(value)).toBe('');
      expect(canvasFormatTime(value)).toBe('');
    },
  );

  it('returns null and undefined unchanged from canvasFormatDateRange', () => {
    expect(canvasFormatDateRange(null)).toBeNull();
    expect(canvasFormatDateRange(undefined)).toBeUndefined();
  });
});

describe('values without a UTC offset in a non-UTC timezone', () => {
  // Node re-reads process.env.TZ on assignment, so the tests below run in
  // Eastern Time (UTC-5 in January) regardless of the machine's timezone.
  const originalTz = process.env.TZ;
  beforeEach(() => {
    process.env.TZ = 'America/New_York';
    setLangcode('en');
  });
  afterEach(() => {
    if (originalTz === undefined) {
      delete process.env.TZ;
    } else {
      process.env.TZ = originalTz;
    }
    clearLocale();
  });

  const normalize = (value: string): string => value.replaceAll(' ', ' ');

  it('treats a datetime without an offset as UTC', () => {
    // Without the fix the value parses as 04:30 local, i.e. 09:30 UTC.
    expect(normalize(canvasFormatDateTime('2026-01-16T04:30:00'))).toBe(
      normalize(canvasFormatDateTime('2026-01-16T04:30:00Z')),
    );
    expect(normalize(canvasFormatDateTime('2026-01-16T04:30:00'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('treats a datetime with milliseconds and no offset as UTC', () => {
    expect(normalize(canvasFormatDateTime('2026-01-16T04:30:00.000'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('treats a bare time as UTC', () => {
    expect(normalize(canvasFormatTime('14:30:00'))).toBe('2:30 PM');
    expect(normalize(canvasFormatTime('14:30'))).toBe('2:30 PM');
  });

  it('converts a datetime with an explicit non-UTC offset to UTC', () => {
    expect(normalize(canvasFormatDateTime('2026-01-15T23:30:00-05:00'))).toBe(
      '1/16/26, 4:30 AM',
    );
  });

  it('does not shift a date-only value', () => {
    expect(canvasFormatDate('2026-01-16')).toBe('1/16/26');
  });
});

describe('canvasFormatDateRange', () => {
  beforeEach(() => setLangcode('en'));
  afterEach(() => clearLocale());

  it('formats both from and to fields', () => {
    const result = canvasFormatDateRange({
      from: '2026-01-15',
      to: '2026-03-20',
    });
    expect(result.from).not.toBe('2026-01-15');
    expect(result.to).not.toBe('2026-03-20');
    expect(typeof result.from).toBe('string');
    expect(typeof result.to).toBe('string');
  });

  it('passes null fields through unchanged', () => {
    const result = canvasFormatDateRange({ from: '2026-01-15', to: null });
    expect(result.from).not.toBe('2026-01-15');
    expect(result.to).toBeNull();
  });

  it('handles absent fields without error', () => {
    const result = canvasFormatDateRange<{
      from?: string | null;
      to?: string | null;
    }>({ from: '2026-01-15' });
    expect(result.from).not.toBe('2026-01-15');
    expect(result.to).toBeUndefined();
  });

  it('returns a new object (does not mutate the input)', () => {
    const input = { from: '2026-01-15', to: '2026-03-20' };
    canvasFormatDateRange(input);
    expect(input.from).toBe('2026-01-15');
    expect(input.to).toBe('2026-03-20');
  });

  it('passes through empty strings unchanged', () => {
    const result = canvasFormatDateRange({ from: '', to: '' });
    expect(result.from).toBe('');
    expect(result.to).toBe('');
  });

  it('accepts a dateStyle option and passes it to each field', () => {
    const shortResult = canvasFormatDateRange({
      from: '2026-01-15',
      to: '2026-03-20',
    });
    const longResult = canvasFormatDateRange(
      { from: '2026-01-15', to: '2026-03-20' },
      { dateStyle: 'long' },
    );
    expect((longResult.from ?? '').length).toBeGreaterThan(
      (shortResult.from ?? '').length,
    );
    expect((longResult.to ?? '').length).toBeGreaterThan(
      (shortResult.to ?? '').length,
    );
  });
});
