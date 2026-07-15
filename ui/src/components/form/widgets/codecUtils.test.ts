import { describe, expect, it } from 'vitest';

import { scalarCodec } from './codecUtils';
import { optionsSelectWidget } from './widgets/OptionsSelectWidget';

import type { ClientWidgetContext } from './types';

const context = (
  jsonSchema: Record<string, unknown>,
  cardinality = 1,
): ClientWidgetContext =>
  ({
    propName: 'prop',
    componentId: 'sdc.test.foo',
    componentVersion: 'v1',
    jsonSchema,
    sourceTypeSettings: { cardinality },
    cardinality,
    required: false,
    fieldData: {},
  }) as unknown as ClientWidgetContext;

describe('scalarCodec', () => {
  it('passes strings through and removes empty values', () => {
    const ctx = context({ type: 'string' });
    expect(scalarCodec.toModel('hello', ctx)).toEqual({ resolved: 'hello' });
    expect(scalarCodec.toModel('', ctx)).toBeNull();
    expect(scalarCodec.toModel(null, ctx)).toBeNull();
    expect(scalarCodec.fromModel(undefined, 'hi', ctx)).toBe('hi');
    expect(scalarCodec.fromModel(undefined, undefined, ctx)).toBe('');
  });

  it('casts to the schema type like the cast transform', () => {
    expect(scalarCodec.toModel('4.5', context({ type: 'number' }))).toEqual({
      resolved: 4.5,
    });
    expect(scalarCodec.toModel('4.5', context({ type: 'integer' }))).toEqual({
      resolved: 4,
    });
    expect(scalarCodec.toModel('abc', context({ type: 'number' }))).toBeNull();
  });
});

describe('options_select codec', () => {
  const { codec, isEligible } = optionsSelectWidget;

  it('is only eligible when the schema carries an enum', () => {
    expect(isEligible!(context({ type: 'string' }))).toBe(false);
    expect(isEligible!(context({ type: 'string', enum: ['a', 'b'] }))).toBe(
      true,
    );
    expect(
      isEligible!(
        context({ type: 'array', items: { type: 'string', enum: ['a'] } }, -1),
      ),
    ).toBe(true);
  });

  it('maps single selections with type casting and _none as empty', () => {
    const intEnum = context({ type: 'integer', enum: [1, 2] });
    expect(codec.toModel('2', intEnum)).toEqual({ resolved: 2 });
    expect(codec.toModel('_none', intEnum)).toBeNull();
    expect(codec.fromModel(undefined, 2, intEnum)).toBe('2');
    expect(codec.fromModel(undefined, undefined, intEnum)).toBe('_none');
  });

  it('maps multi-value selections to arrays of cast values', () => {
    const multi = context(
      { type: 'array', items: { type: 'integer', enum: [1, 2, 3] } },
      -1,
    );
    expect(codec.toModel(['1', '3'], multi)).toEqual({ resolved: [1, 3] });
    expect(codec.toModel([], multi)).toBeNull();
    expect(codec.fromModel(undefined, [1, 3], multi)).toEqual(['1', '3']);
  });
});
