import { describe, expect, it } from 'vitest';

import { compilePropStates, evaluatePropStates } from './propStates';

import type { FieldData } from '@/types/Component';

const propSources = (states: unknown): FieldData =>
  ({
    has_link: {
      expression: 'ℹ︎boolean␟value',
      sourceType: 'static:field_item:boolean',
      field_widget: 'boolean_checkbox',
      default_values: { resolved: {}, source: {} },
      jsonSchema: { type: 'boolean' },
    },
    link_target: {
      expression: 'ℹ︎string␟value',
      sourceType: 'static:field_item:string',
      field_widget: 'string_textfield',
      default_values: { resolved: {}, source: {} },
      jsonSchema: { type: 'string', 'x-canvas-states': states },
    },
  }) as unknown as FieldData;

const visibleWhenHasLink = [
  {
    effect: 'visible',
    when: { properties: { has_link: { const: true } }, required: ['has_link'] },
  },
];

describe('x-canvas-states evaluation', () => {
  it('shows a prop only while its condition schema matches sibling values', () => {
    const compiled = compilePropStates(propSources(visibleWhenHasLink));
    expect(
      evaluatePropStates(compiled, { has_link: true }).link_target,
    ).toEqual({ visible: true, enabled: true });
    expect(
      evaluatePropStates(compiled, { has_link: false }).link_target,
    ).toEqual({ visible: false, enabled: true });
    // A missing sibling value fails a `required` condition.
    expect(evaluatePropStates(compiled, {}).link_target).toEqual({
      visible: false,
      enabled: true,
    });
  });

  it('supports the enabled effect independently of visibility', () => {
    const compiled = compilePropStates(
      propSources([
        {
          effect: 'enabled',
          when: { properties: { has_link: { const: true } } },
        },
      ]),
    );
    expect(
      evaluatePropStates(compiled, { has_link: false }).link_target,
    ).toEqual({ visible: true, enabled: false });
  });

  it('ANDs multiple rules of the same effect', () => {
    const compiled = compilePropStates(
      propSources([
        ...visibleWhenHasLink,
        {
          effect: 'visible',
          when: { properties: { has_link: { type: 'boolean' } } },
        },
      ]),
    );
    expect(
      evaluatePropStates(compiled, { has_link: false }).link_target.visible,
    ).toBe(false);
    expect(
      evaluatePropStates(compiled, { has_link: true }).link_target.visible,
    ).toBe(true);
  });

  it('ignores unknown effects and malformed rules (forward-extensible)', () => {
    const compiled = compilePropStates(
      propSources([
        { effect: 'required', when: { properties: {} } },
        { effect: 'visible' },
        'garbage',
      ]),
    );
    // No valid rules compile, so the prop gets no state entry (defaults).
    expect(compiled.link_target).toBeUndefined();
  });

  it('compiles nothing for components without state rules', () => {
    expect(compilePropStates(propSources(undefined))).toEqual({});
  });
});
