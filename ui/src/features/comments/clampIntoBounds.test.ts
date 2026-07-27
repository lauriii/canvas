import { describe, expect, it } from 'vitest';

import { clampIntoBounds } from '@/features/comments/clampIntoBounds';

const bounds = { left: 100, right: 500 };

describe('clampIntoBounds', () => {
  it('leaves something already inside alone', () => {
    expect(clampIntoBounds({ top: 10, left: 200, right: 300 }, bounds)).toEqual(
      {
        x: 0,
        y: 0,
      },
    );
  });

  it('pulls back something hanging off the right', () => {
    // This is the case that hid the composer's submit button behind the panel.
    expect(clampIntoBounds({ top: 10, left: 450, right: 690 }, bounds)).toEqual(
      {
        x: -190,
        y: 0,
      },
    );
  });

  it('pushes in something hanging off the left', () => {
    expect(clampIntoBounds({ top: 10, left: 40, right: 280 }, bounds)).toEqual({
      x: 60,
      y: 0,
    });
  });

  it('aligns left when it cannot fit either way', () => {
    // Wider than its container: overflowing the right edge is the lesser evil,
    // because the left edge is where the content starts.
    expect(clampIntoBounds({ top: 10, left: 90, right: 800 }, bounds)).toEqual({
      x: 10,
      y: 0,
    });
  });

  it('drops something that would sit above the window back on screen', () => {
    expect(
      clampIntoBounds({ top: -30, left: 200, right: 300 }, bounds),
    ).toEqual({ x: 0, y: 30 });
  });
});
