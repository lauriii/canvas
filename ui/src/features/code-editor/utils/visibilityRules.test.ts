import { describe, expect, it } from 'vitest';

import {
  conditionToRules,
  operatorsFor,
  replacePropInCondition,
  rulesToCondition,
} from './visibilityRules';

import type { VisibilityCondition } from '@/utils/propVisibility';
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

describe('incomplete rules', () => {
  it('leaves out rules without a value', () => {
    expect(
      rulesToCondition({
        match: 'all',
        rules: [
          { prop: 'show', operator: 'on' },
          { prop: 'count', operator: 'gt' },
          { prop: 'title', operator: 'is', value: '' },
        ],
      }),
    ).toEqual({ show: true });
  });
});

describe('operatorsFor', () => {
  it('offers contains for props with multiple values', () => {
    expect(operatorsFor('listText', true)).toEqual([
      'contains',
      'filled',
      'empty',
    ]);
    expect(operatorsFor('listText')).toContain('is');
  });
});

describe('replacePropInCondition', () => {
  const condition: VisibilityCondition = {
    show: true,
    anyOf: [{ count: { gte: 2 } }, { show: false }],
    not: { show: false },
  };

  it('follows a renamed prop', () => {
    expect(replacePropInCondition(condition, 'show', 'visible')).toEqual({
      visible: true,
      anyOf: [{ count: { gte: 2 } }, { visible: false }],
      not: { visible: false },
    });
  });

  it('drops the tests of a removed prop', () => {
    expect(replacePropInCondition(condition, 'show', null)).toEqual({
      anyOf: [{ count: { gte: 2 } }],
    });
    expect(
      replacePropInCondition({ show: true }, 'show', null),
    ).toBeUndefined();
  });
});
