import { describe, expect, it } from 'vitest';

import transforms from '@/utils/transforms';

import {
  dateRangeDefaultWidget,
  dateTimeDefaultWidget,
} from './DateTimeWidgets';

import type { ClientWidgetContext } from '../types';

const makeContext = (
  datetimeType: 'date' | 'datetime',
): ClientWidgetContext => ({
  propName: 'when',
  componentId: 'sdc.test.event',
  componentVersion: '1',
  jsonSchema: { type: 'string' },
  sourceTypeSettings: { storage: { datetime_type: datetimeType } },
  cardinality: 1,
  required: false,
  fieldData: {} as ClientWidgetContext['fieldData'],
});

// The prop source shape the transforms receive; only the storage settings
// matter for the date transforms.
const makePropSource = (datetimeType: 'date' | 'datetime') =>
  ({
    sourceTypeSettings: { storage: { datetime_type: datetimeType } },
  }) as Parameters<typeof transforms.dateTime>[2];

describe('dateTimeDefaultWidget codec', () => {
  const { codec } = dateTimeDefaultWidget;

  it('formats date-only storage as the plain date string', () => {
    expect(
      codec.toModel({ date: '2025-03-04', time: '' }, makeContext('date')),
    ).toEqual({ resolved: '2025-03-04' });
  });

  it('formats datetime storage as a UTC ISO string', () => {
    expect(
      codec.toModel(
        { date: '2025-03-04', time: '08:30:00' },
        makeContext('datetime'),
      ),
    ).toEqual({ resolved: '2025-03-04T08:30:00.000Z' });
  });

  it('defaults an empty time to noon like the dateTime transform', () => {
    expect(
      codec.toModel({ date: '2025-03-04', time: '' }, makeContext('datetime')),
    ).toEqual({ resolved: '2025-03-04T12:00:00.000Z' });
  });

  it('matches the dateTime transform output', () => {
    (
      [
        ['date', { date: '2024-12-31', time: '' }],
        ['datetime', { date: '2024-12-31', time: '' }],
        ['datetime', { date: '2024-12-31', time: '23:59:59' }],
      ] as const
    ).forEach(([type, record]) => {
      expect(codec.toModel(record, makeContext(type))?.resolved).toEqual(
        transforms.dateTime([{ ...record }], { type }, makePropSource(type)),
      );
    });
  });

  it('returns null for an empty date', () => {
    expect(
      codec.toModel({ date: '', time: '08:30:00' }, makeContext('datetime')),
    ).toBeNull();
    expect(
      codec.toModel({ date: '', time: '' }, makeContext('date')),
    ).toBeNull();
    expect(codec.toModel(undefined, makeContext('datetime'))).toBeNull();
  });

  it('returns null for an unparseable date', () => {
    expect(
      codec.toModel({ date: 'not-a-date', time: '' }, makeContext('datetime')),
    ).toBeNull();
  });

  it('parses a stored ISO string back into UTC date and time parts', () => {
    expect(
      codec.fromModel(
        undefined,
        '2025-03-04T08:30:00.000Z',
        makeContext('datetime'),
      ),
    ).toEqual({ date: '2025-03-04', time: '08:30:00' });
  });

  it('parses a stored date-only string', () => {
    expect(
      codec.fromModel(undefined, '2025-03-04', makeContext('date')),
    ).toEqual({ date: '2025-03-04', time: '' });
  });

  it('maps an empty model value to empty inputs', () => {
    expect(
      codec.fromModel(undefined, undefined, makeContext('datetime')),
    ).toEqual({ date: '', time: '' });
  });

  it('round-trips a datetime value', () => {
    const context = makeContext('datetime');
    const widgetValue = { date: '2025-03-04', time: '08:30:00' };
    const result = codec.toModel(widgetValue, context);
    expect(
      codec.fromModel(result?.resolved, result?.resolved, context),
    ).toEqual(widgetValue);
  });
});

describe('dateRangeDefaultWidget codec', () => {
  const { codec } = dateRangeDefaultWidget;

  it('formats a complete datetime range', () => {
    expect(
      codec.toModel(
        {
          start: { date: '2025-03-04', time: '08:30:00' },
          end: { date: '2025-03-05', time: '' },
        },
        makeContext('datetime'),
      ),
    ).toEqual({
      resolved: {
        value: '2025-03-04T08:30:00.000Z',
        end_value: '2025-03-05T12:00:00.000Z',
      },
    });
  });

  it('formats a complete date-only range', () => {
    expect(
      codec.toModel(
        {
          start: { date: '2025-03-04', time: '' },
          end: { date: '2025-03-05', time: '' },
        },
        makeContext('date'),
      ),
    ).toEqual({
      resolved: { value: '2025-03-04', end_value: '2025-03-05' },
    });
  });

  it('matches the dateRange transform output', () => {
    const start = { date: '2025-03-04', time: '08:30:00' };
    const end = { date: '2025-03-05', time: '' };
    expect(
      codec.toModel({ start, end }, makeContext('datetime'))?.resolved,
    ).toEqual(
      transforms.dateRange(
        { value: { ...start }, end_value: { ...end } },
        {},
        makePropSource('datetime') as Parameters<
          typeof transforms.dateRange
        >[2],
      ),
    );
  });

  it('returns null when either date is missing', () => {
    expect(
      codec.toModel(
        {
          start: { date: '2025-03-04', time: '' },
          end: { date: '', time: '' },
        },
        makeContext('datetime'),
      ),
    ).toBeNull();
    expect(
      codec.toModel(
        {
          start: { date: '', time: '' },
          end: { date: '2025-03-05', time: '' },
        },
        makeContext('datetime'),
      ),
    ).toBeNull();
    expect(codec.toModel(undefined, makeContext('datetime'))).toBeNull();
  });

  it('parses a stored range back into start and end pairs', () => {
    expect(
      codec.fromModel(
        undefined,
        {
          value: '2025-03-04T08:30:00.000Z',
          end_value: '2025-03-05T12:00:00.000Z',
        },
        makeContext('datetime'),
      ),
    ).toEqual({
      start: { date: '2025-03-04', time: '08:30:00' },
      end: { date: '2025-03-05', time: '12:00:00' },
    });
  });

  it('maps an empty model value to empty pairs', () => {
    expect(codec.fromModel(undefined, undefined, makeContext('date'))).toEqual({
      start: { date: '', time: '' },
      end: { date: '', time: '' },
    });
  });
});
