import { describe, expect, it, vi } from 'vitest';

import { formatTimestamp } from './formatTimestamp';

describe('formatTimestamp', () => {
  it('returns "Now" for less than 1 minute ago', () => {
    vi.spyOn(Date, 'now').mockReturnValue(1000000);
    expect(formatTimestamp(1000000)).toBe('Now');
    expect(formatTimestamp(1000000 - 30000)).toBe('Now');
  });

  it('returns "Xm ago" for less than 60 minutes ago', () => {
    vi.spyOn(Date, 'now').mockReturnValue(1000000);
    expect(formatTimestamp(1000000 - 60000)).toBe('1m ago');
    expect(formatTimestamp(1000000 - 300000)).toBe('5m ago');
    expect(formatTimestamp(1000000 - 3540000)).toBe('59m ago');
  });

  it('returns "Xh ago" for less than 24 hours ago', () => {
    vi.spyOn(Date, 'now').mockReturnValue(10000000);
    expect(formatTimestamp(10000000 - 3600000)).toBe('1h ago');
    expect(formatTimestamp(10000000 - 7200000)).toBe('2h ago');
  });

  it('returns "Xd ago" for 24 hours or more', () => {
    vi.spyOn(Date, 'now').mockReturnValue(100000000);
    expect(formatTimestamp(100000000 - 86400000)).toBe('1d ago');
    expect(formatTimestamp(100000000 - 172800000)).toBe('2d ago');
  });
});
