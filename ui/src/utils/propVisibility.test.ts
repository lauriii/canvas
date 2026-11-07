import { describe, expect, it } from 'vitest';

import {
  computeDepths,
  computeVisibility,
  passesCondition,
} from './propVisibility';

import type { VisibilityCondition } from './propVisibility';

describe('passesCondition', () => {
  it.each<[VisibilityCondition, Record<string, unknown>, boolean]>([
    // Equality, also across strings and numbers.
    [{ show: true }, { show: true }, true],
    [{ show: true }, { show: false }, false],
    [{ show: true }, {}, false],
    [{ count: 2 }, { count: '2' }, true],
    // One of.
    [{ layout: ['split', 'grid'] }, { layout: 'grid' }, true],
    [{ layout: ['split', 'grid'] }, { layout: 'stack' }, false],
    // Operators.
    [{ layout: { not: 'stack' } }, { layout: 'grid' }, true],
    [{ layout: { not: ['stack', 'grid'] } }, { layout: 'grid' }, false],
    [{ count: { gte: 2 } }, { count: 2 }, true],
    [{ count: { gte: 2, lt: 4 } }, { count: 4 }, false],
    [{ count: { gt: 0 } }, { count: null }, false],
    [{ count: { lte: 0 } }, {}, false],
    [{ title: { contains: 'sale' } }, { title: 'Big sale' }, true],
    [{ tags: { contains: 'new' } }, { tags: ['new', 'hot'] }, true],
    [{ tags: { contains: 'old' } }, { tags: ['new'] }, false],
    [{ image: { filled: true } }, { image: { src: 'a.png' } }, true],
    [{ image: { filled: true } }, { image: null }, false],
    [{ text: { filled: false } }, { text: '' }, true],
    [{ items: { filled: true } }, { items: [] }, false],
    // Combinations: all keys must pass, anyOf, allOf and not.
    [{ show: true, count: 2 }, { show: true, count: 1 }, false],
    [
      { anyOf: [{ show: true }, { count: 2 }] },
      { show: false, count: 2 },
      true,
    ],
    [
      { anyOf: [{ show: true }, { count: 2 }] },
      { show: false, count: 1 },
      false,
    ],
    [{ allOf: [{ show: true }, { count: 2 }] }, { show: true, count: 2 }, true],
    [{ not: { layout: 'stack' } }, { layout: 'stack' }, false],
  ])('%j with %j is %s', (condition, values, expected) => {
    expect(passesCondition(condition, values)).toBe(expected);
  });
});

describe('computeVisibility', () => {
  const conditions: Record<string, VisibilityCondition> = {
    button_text: { show_buttons: true },
    show_second: { show_buttons: true },
    second_text: { show_second: true },
  };

  it('shows props whose conditions pass', () => {
    expect(
      computeVisibility(conditions, { show_buttons: true, show_second: true }),
    ).toEqual({ button_text: true, show_second: true, second_text: true });
  });

  it('treats hidden props as empty', () => {
    expect(
      computeVisibility(conditions, { show_buttons: false, show_second: true }),
    ).toEqual({ button_text: false, show_second: false, second_text: false });
  });

  it('does not loop on cycles', () => {
    expect(
      computeVisibility(
        { a: { b: true }, b: { a: true } },
        { a: true, b: true },
      ),
    ).toEqual({ a: true, b: true });
  });
});

describe('computeDepths', () => {
  it('nests props under the props they depend on', () => {
    expect(
      computeDepths({
        count: { show: true },
        second: { count: { gte: 2 } },
        note: { anyOf: [{ second: { filled: true } }, { show: false }] },
      }),
    ).toEqual({ count: 1, second: 2, note: 3 });
  });
});
