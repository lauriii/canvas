/**
 * Evaluates `x-canvas-visible-if`: when a prop is shown in the component form.
 *
 * @see \Drupal\canvas\JsonSchemaInterpreter\PropVisibilityCondition
 */

type Scalar = string | number | boolean;

export type VisibilityTest =
  | Scalar
  | Scalar[]
  | {
      not?: Scalar | Scalar[];
      gt?: number;
      gte?: number;
      lt?: number;
      lte?: number;
      contains?: Scalar;
      filled?: boolean;
    };

// Prop names map to tests. `anyOf` and `allOf` map to lists of conditions, and
// `not` to a condition.
export type VisibilityCondition = {
  [key: string]: VisibilityTest | VisibilityCondition | VisibilityCondition[];
};

export const isFilled = (value: unknown): boolean =>
  value !== undefined &&
  value !== null &&
  value !== '' &&
  !(Array.isArray(value) && value.length === 0);

// Enum values may arrive as strings from form elements, so compare loosely:
// `3` matches `'3'`.
const matches = (value: unknown, expected: Scalar | Scalar[]): boolean =>
  (Array.isArray(expected) ? expected : [expected]).some(
    (item) =>
      value === item || (isFilled(value) && String(value) === String(item)),
  );

const passesTest = (value: unknown, test: VisibilityTest): boolean => {
  if (typeof test !== 'object' || Array.isArray(test)) {
    return matches(value, test);
  }
  const number = Number(value);
  const comparable = isFilled(value) && !Number.isNaN(number);
  return Object.entries(test).every(([operator, operand]) => {
    switch (operator) {
      case 'not':
        return !matches(value, operand as Scalar | Scalar[]);
      case 'gt':
        return comparable && number > (operand as number);
      case 'gte':
        return comparable && number >= (operand as number);
      case 'lt':
        return comparable && number < (operand as number);
      case 'lte':
        return comparable && number <= (operand as number);
      case 'contains':
        return Array.isArray(value)
          ? matches(operand, value as Scalar[])
          : typeof value === 'string' && value.includes(String(operand));
      case 'filled':
        return isFilled(value) === operand;
      default:
        return false;
    }
  });
};

export const passesCondition = (
  condition: VisibilityCondition,
  values: Record<string, unknown>,
): boolean =>
  Object.entries(condition).every(([key, test]) => {
    if (key === 'anyOf') {
      return (test as VisibilityCondition[]).some((sub) =>
        passesCondition(sub, values),
      );
    }
    if (key === 'allOf') {
      return (test as VisibilityCondition[]).every((sub) =>
        passesCondition(sub, values),
      );
    }
    if (key === 'not') {
      return !passesCondition(test as VisibilityCondition, values);
    }
    return passesTest(values[key], test as VisibilityTest);
  });

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

/**
 * Computes how deeply each prop with a condition is nested.
 *
 * A prop that depends on props without a condition has depth 1; one that
 * depends on a prop of depth 1 has depth 2, and so on.
 */
export const computeDepths = (
  conditions: Record<string, VisibilityCondition>,
): Record<string, number> => {
  const depths: Record<string, number> = {};
  const depthOf = (propName: string): number => {
    if (!(propName in conditions)) {
      return 0;
    }
    if (!(propName in depths)) {
      // Guards against cycles, which the server already rejects.
      depths[propName] = 1;
      depths[propName] =
        1 + Math.max(0, ...referencedProps(conditions[propName]).map(depthOf));
    }
    return depths[propName];
  };
  Object.keys(conditions).forEach(depthOf);
  return depths;
};

/**
 * Computes which props are visible.
 *
 * A hidden prop counts as empty in the conditions of other props: "Second
 * button text" stays hidden while "Show buttons", which shows "Show a second
 * button", is off.
 *
 * @param conditions
 *   The conditions, keyed by prop name. Props without one are always visible.
 * @param values
 *   The current prop values, keyed by prop name.
 *
 * @returns
 *   The visibility of each prop that has a condition.
 */
export const computeVisibility = (
  conditions: Record<string, VisibilityCondition>,
  values: Record<string, unknown>,
): Record<string, boolean> => {
  const visibility: Record<string, boolean> = {};
  const isVisible = (propName: string): boolean => {
    if (!(propName in conditions)) {
      return true;
    }
    if (!(propName in visibility)) {
      // Guards against cycles, which the server already rejects.
      visibility[propName] = true;
      // Lazily, so only the props that the condition reads are evaluated.
      const effectiveValues = new Proxy(values, {
        get: (target, name: string) =>
          isVisible(name) ? target[name] : undefined,
      });
      visibility[propName] = passesCondition(
        conditions[propName],
        effectiveValues,
      );
    }
    return visibility[propName];
  };
  Object.keys(conditions).forEach(isVisible);
  return visibility;
};
