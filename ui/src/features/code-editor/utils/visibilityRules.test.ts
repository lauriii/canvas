import { describe, expect, it } from 'vitest';

import { conditionToRules, rulesToCondition } from './visibilityRules';

import type { VisibilityRules } from './visibilityRules';

describe('visibility rules', () => {
  it.each<[string, VisibilityRules, unknown]>([
    [
      'a switch',
      { match: 'all', rules: [{ prop: 'show', operator: 'on' }] },
      { show: true },
    ],
    [
      'all of several props',
      {
        match: 'all',
        rules: [
          { prop: 'show', operator: 'on' },
          { prop: 'count', operator: 'gte', value: 2 },
          { prop: 'title', operator: 'filled' },
        ],
      },
      { show: true, count: { gte: 2 }, title: { filled: true } },
    ],
    [
      'any of several props',
      {
        match: 'any',
        rules: [
          { prop: 'layout', operator: 'is', value: 'split' },
          { prop: 'image', operator: 'empty' },
        ],
      },
      { anyOf: [{ layout: 'split' }, { image: { filled: false } }] },
    ],
    [
      'all of several rules on one prop',
      {
        match: 'all',
        rules: [
          { prop: 'count', operator: 'gt', value: 1 },
          { prop: 'count', operator: 'not', value: 3 },
        ],
      },
      { allOf: [{ count: { gt: 1 } }, { count: { not: 3 } }] },
    ],
  ])('round-trips %s', (_, rules, condition) => {
    expect(rulesToCondition(rules)).toEqual(condition);
    expect(rulesToCondition(conditionToRules(condition as never)!)).toEqual(
      condition,
    );
  });

  it('has no condition without rules', () => {
    expect(rulesToCondition({ match: 'all', rules: [] })).toBeUndefined();
    expect(
      rulesToCondition({
        match: 'all',
        rules: [{ prop: '', operator: 'on' }],
      }),
    ).toBeUndefined();
  });

  it('reads several operators on one prop as several rules', () => {
    expect(conditionToRules({ count: { gte: 2, lt: 5 } })).toEqual({
      match: 'all',
      rules: [
        { prop: 'count', operator: 'gte', value: 2 },
        { prop: 'count', operator: 'lt', value: 5 },
      ],
    });
  });

  it.each([
    [{ layout: ['split', 'grid'] }],
    [{ not: { show: true } }],
    [{ anyOf: [{ show: true, count: 2 }, { title: { filled: true } }] }],
  ])('cannot represent %j', (condition) => {
    expect(conditionToRules(condition as never)).toBeNull();
  });
});
