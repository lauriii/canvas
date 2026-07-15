import Ajv from 'ajv';

import type { ValidateFunction } from 'ajv';
import type { FieldData } from '@/types/Component';
import type { PropSlotState } from './NativePropSlot';

/**
 * Declarative conditional prop states (`x-canvas-states`).
 *
 * A prop's schema may declare a list of `{effect, when}` rules where `effect`
 * is `visible` or `enabled` and `when` is a JSON Schema evaluated against the
 * component instance's resolved prop values. Rules address sibling props by
 * name through the condition schema, never by DOM selector.
 *
 * Effects in v1 are `visible` and `enabled` only; conditional `required` is
 * deliberately out of scope (it needs client/server validation agreement).
 * A hidden prop keeps its model value.
 */
export const PROP_STATES_SCHEMA_KEY = 'x-canvas-states';

export type PropStateEffect = 'visible' | 'enabled';

export interface PropStateRule {
  effect: PropStateEffect;
  when: object;
}

interface CompiledRule {
  effect: PropStateEffect;
  validate: ValidateFunction;
}

export type CompiledPropStates = Record<string, CompiledRule[]>;

// A dedicated ajv instance for condition schemas. Conditions are terse
// hand-written schemas like `{properties: {...}, required: [...]}`, so ajv's
// strict-mode "missing type" pedantry is disabled; unknown keywords still log.
const ajv = new Ajv({ strictTypes: false });

const isPropStateRule = (rule: unknown): rule is PropStateRule =>
  typeof rule === 'object' &&
  rule !== null &&
  'effect' in rule &&
  ['visible', 'enabled'].includes((rule as PropStateRule).effect) &&
  'when' in rule &&
  typeof (rule as PropStateRule).when === 'object' &&
  (rule as PropStateRule).when !== null;

/**
 * Compiles every prop's state rules for a component version. Invalid or
 * unknown rule entries are ignored (unknown effects may be added later;
 * ignoring them keeps the vocabulary forward-extensible).
 */
export function compilePropStates(propSources: FieldData): CompiledPropStates {
  const compiled: CompiledPropStates = {};
  Object.entries(propSources).forEach(([propName, fieldData]) => {
    const rules = fieldData.jsonSchema?.[
      PROP_STATES_SCHEMA_KEY as keyof typeof fieldData.jsonSchema
    ] as unknown;
    if (!Array.isArray(rules)) {
      return;
    }
    const compiledRules: CompiledRule[] = [];
    rules.filter(isPropStateRule).forEach((rule) => {
      try {
        compiledRules.push({
          effect: rule.effect,
          validate: ajv.compile(rule.when),
        });
      } catch (e) {
        // A malformed condition schema disables the rule rather than the
        // form; authors get feedback via the console.
        console.warn(
          `Ignoring invalid x-canvas-states rule on prop "${propName}":`,
          e,
        );
      }
    });
    if (compiledRules.length > 0) {
      compiled[propName] = compiledRules;
    }
  });
  return compiled;
}

/**
 * Evaluates compiled state rules against the current resolved prop values.
 * Evaluation is synchronous and client-side only. Every effect of a kind must
 * pass for the prop to be visible/enabled (rules of the same effect AND).
 */
export function evaluatePropStates(
  compiled: CompiledPropStates,
  resolvedValues: Record<string, unknown>,
): Record<string, PropSlotState> {
  const states: Record<string, PropSlotState> = {};
  Object.entries(compiled).forEach(([propName, rules]) => {
    const state: PropSlotState = { visible: true, enabled: true };
    rules.forEach(({ effect, validate }) => {
      const passes = Boolean(validate(resolvedValues));
      if (effect === 'visible') {
        state.visible = state.visible && passes;
      } else {
        state.enabled = state.enabled && passes;
      }
    });
    states[propName] = state;
  });
  return states;
}

export const DEFAULT_SLOT_STATE: PropSlotState = {
  visible: true,
  enabled: true,
};
