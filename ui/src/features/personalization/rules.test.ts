import { describe, expect, it } from 'vitest';

import { createDefaultRule, ruleSummary } from './rules';

describe('createDefaultRule', () => {
  it('creates non-negated defaults for every condition type', () => {
    expect(createDefaultRule('query_parameter')).toEqual({
      id: 'query_parameter',
      negate: false,
      parameter: '',
      value: '',
      matching: 'exact',
    });
    expect(createDefaultRule('utm_parameters')).toEqual({
      id: 'utm_parameters',
      negate: false,
      all: true,
      parameters: [{ key: 'utm_source', value: '', matching: 'exact' }],
    });
    expect(createDefaultRule('geolocation')).toEqual({
      id: 'geolocation',
      negate: false,
      countries: [],
      regions: [],
    });
    expect(createDefaultRule('day_of_week')).toEqual({
      id: 'day_of_week',
      negate: false,
      days: [],
    });
  });
});

describe('ruleSummary', () => {
  it('summarizes query parameter rules', () => {
    expect(
      ruleSummary({
        id: 'query_parameter',
        negate: false,
        parameter: 'coupon',
        value: 'spring',
        matching: 'exact',
      }),
    ).toBe('The "coupon" query parameter equals "spring"');
    expect(
      ruleSummary({
        id: 'query_parameter',
        negate: false,
        parameter: 'coupon',
        value: '',
        matching: 'present',
      }),
    ).toBe('The URL includes the "coupon" query parameter');
  });

  it('summarizes UTM parameter rules with and/or wording', () => {
    const rule = {
      id: 'utm_parameters' as const,
      negate: false,
      all: true,
      parameters: [
        { key: 'utm_source', value: 'news', matching: 'exact' as const },
        { key: 'utm_medium', value: 'em', matching: 'starts_with' as const },
      ],
    };
    expect(ruleSummary(rule)).toBe(
      'The URL matches utm_source equals "news" and utm_medium starts with "em"',
    );
    expect(ruleSummary({ ...rule, all: false })).toBe(
      'The URL matches utm_source equals "news" or utm_medium starts with "em"',
    );
  });

  it('summarizes geolocation rules including negation', () => {
    expect(
      ruleSummary({
        id: 'geolocation',
        negate: true,
        countries: ['US', 'CA'],
        regions: ['NY'],
      }),
    ).toBe('Everyone except: the visitor is in US, CA (regions: NY)');
  });

  it('summarizes day of week rules', () => {
    expect(
      ruleSummary({
        id: 'day_of_week',
        negate: false,
        days: ['monday', 'friday'],
      }),
    ).toBe('The visit happens on Monday, Friday');
    expect(ruleSummary({ id: 'day_of_week', negate: false, days: [] })).toBe(
      'No days selected yet',
    );
  });
});
