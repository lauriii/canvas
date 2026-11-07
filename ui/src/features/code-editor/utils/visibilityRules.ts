/**
 * Converts `x-canvas-visible-if` conditions from and to the rules that the code
 * editor's "Show when" builder edits.
 *
 * @see ui/src/utils/propVisibility.ts
 */
import type {
  VisibilityCondition,
  VisibilityTest,
} from '@/utils/propVisibility';

export const OPERATORS = {
  on: 'is on',
  off: 'is off',
  is: 'is',
  not: 'is not',
  gt: 'is more than',
  gte: 'is at least',
  lt: 'is less than',
  lte: 'is at most',
  contains: 'contains',
  filled: 'is filled',
  empty: 'is empty',
} as const;

export type Operator = keyof typeof OPERATORS;

export const OPERATORS_WITHOUT_VALUE: Operator[] = [
  'on',
  'off',
  'filled',
  'empty',
];

export interface VisibilityRule {
  // The machine name of the prop that the rule tests.
  prop: string;
  operator: Operator;
  value?: string | number;
}

export interface VisibilityRules {
  match: 'all' | 'any';
  rules: VisibilityRule[];
}

/**
 * The operators that make sense for a prop of the given derived type.
 */
export const operatorsFor = (
  derivedType: string | null,
  allowMultiple = false,
): Operator[] => {
  // The value of a prop with multiple values is a list.
  if (allowMultiple) {
    return ['contains', 'filled', 'empty'];
  }
  switch (derivedType) {
    case 'boolean':
      return ['on', 'off'];
    case 'listText':
    case 'listInteger':
      return ['is', 'not', 'filled', 'empty'];
    case 'integer':
    case 'number':
      return ['is', 'not', 'gt', 'gte', 'lt', 'lte', 'filled', 'empty'];
    case 'text':
      return ['filled', 'empty', 'is', 'not', 'contains'];
    default:
      return ['filled', 'empty'];
  }
};

const toTest = ({ operator, value }: VisibilityRule): VisibilityTest => {
  switch (operator) {
    case 'on':
      return true;
    case 'off':
      return false;
    case 'is':
      return value ?? '';
    case 'filled':
      return { filled: true };
    case 'empty':
      return { filled: false };
    case 'gt':
    case 'gte':
    case 'lt':
    case 'lte':
      return { [operator]: Number(value) };
    default:
      return { [operator]: value ?? '' };
  }
};

/**
 * Builds the condition for a set of rules.
 *
 * @returns
 *   The condition, or undefined if there are no rules.
 */
export const rulesToCondition = ({
  match,
  rules,
}: VisibilityRules): VisibilityCondition | undefined => {
  const complete = rules.filter(
    (rule) =>
      rule.prop &&
      (OPERATORS_WITHOUT_VALUE.includes(rule.operator) ||
        (rule.value !== undefined && rule.value !== '')),
  );
  if (complete.length === 0) {
    return undefined;
  }
  const conditions = complete.map((rule) => ({ [rule.prop]: toTest(rule) }));
  if (complete.length === 1) {
    return conditions[0];
  }
  const props = complete.map((rule) => rule.prop);
  if (match === 'all' && new Set(props).size === props.length) {
    return Object.assign({}, ...conditions);
  }
  return { [match === 'all' ? 'allOf' : 'anyOf']: conditions };
};

const toRules = (prop: string, test: unknown): VisibilityRule[] | null => {
  if (test === true || test === false) {
    return [{ prop, operator: test ? 'on' : 'off' }];
  }
  if (typeof test === 'string' || typeof test === 'number') {
    return [{ prop, operator: 'is', value: test }];
  }
  if (typeof test !== 'object' || test === null || Array.isArray(test)) {
    return null;
  }
  const rules: VisibilityRule[] = [];
  for (const [operator, operand] of Object.entries(test)) {
    if (operator === 'filled' && typeof operand === 'boolean') {
      rules.push({ prop, operator: operand ? 'filled' : 'empty' });
    } else if (
      ['not', 'gt', 'gte', 'lt', 'lte', 'contains'].includes(operator) &&
      (typeof operand === 'string' || typeof operand === 'number')
    ) {
      rules.push({ prop, operator: operator as Operator, value: operand });
    } else {
      return null;
    }
  }
  return rules;
};

const mapToRules = (condition: object): VisibilityRule[] | null => {
  const rules: VisibilityRule[] = [];
  for (const [key, test] of Object.entries(condition)) {
    const converted =
      key === 'anyOf' || key === 'allOf' || key === 'not'
        ? null
        : toRules(key, test);
    if (!converted) {
      return null;
    }
    rules.push(...converted);
  }
  return rules;
};

/**
 * Reads the rules of a condition.
 *
 * @returns
 *   The rules, or null if the builder cannot represent the condition, such as
 *   a nested one written by hand.
 */
export const conditionToRules = (
  condition: VisibilityCondition | undefined,
): VisibilityRules | null => {
  if (!condition) {
    return { match: 'all', rules: [] };
  }
  const keys = Object.keys(condition);
  if (keys.length === 1 && (keys[0] === 'anyOf' || keys[0] === 'allOf')) {
    const rules = (condition[keys[0]] as VisibilityCondition[]).map(mapToRules);
    // Each alternative of `anyOf` must be a single rule: the builder has no
    // "all of these" inside "any of".
    if (
      rules.some((r) => r === null || (keys[0] === 'anyOf' && r.length !== 1))
    ) {
      return null;
    }
    return {
      match: keys[0] === 'anyOf' ? 'any' : 'all',
      rules: (rules as VisibilityRule[][]).flat(),
    };
  }
  const rules = mapToRules(condition);
  return rules ? { match: 'all', rules } : null;
};

/**
 * Points a condition at a renamed prop, or drops the tests of a removed prop.
 *
 * @param condition
 *   The condition.
 * @param from
 *   The machine name of the renamed or removed prop.
 * @param to
 *   The new machine name, or null if the prop was removed.
 *
 * @returns
 *   The updated condition, or undefined if nothing is left of it.
 */
export const replacePropInCondition = (
  condition: VisibilityCondition,
  from: string,
  to: string | null,
): VisibilityCondition | undefined => {
  const updated: VisibilityCondition = {};
  Object.entries(condition).forEach(([key, test]) => {
    if (key === 'anyOf' || key === 'allOf') {
      const conditions = (test as VisibilityCondition[])
        .map((sub) => replacePropInCondition(sub, from, to))
        .filter((sub): sub is VisibilityCondition => sub !== undefined);
      if (conditions.length) {
        updated[key] = conditions;
      }
    } else if (key === 'not') {
      const inverted = replacePropInCondition(
        test as VisibilityCondition,
        from,
        to,
      );
      if (inverted) {
        updated.not = inverted;
      }
    } else if (key !== from) {
      updated[key] = test;
    } else if (to) {
      updated[to] = test;
    }
  });
  return Object.keys(updated).length ? updated : undefined;
};

/**
 * Lists the props that a condition refers to.
 */
export const referencedProps = (condition: VisibilityCondition): string[] =>
  Object.entries(condition).flatMap(([key, test]) => {
    if (key === 'anyOf' || key === 'allOf') {
      return (test as VisibilityCondition[]).flatMap(referencedProps);
    }
    if (key === 'not') {
      return referencedProps(test as VisibilityCondition);
    }
    return [key];
  });
