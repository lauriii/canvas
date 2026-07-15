import { describe, expect, it } from 'vitest';

import transforms from '@/utils/transforms';

import { entityReferenceAutocompleteWidget } from './EntityAutocompleteWidget';

import type { ClientWidgetContext } from '../types';

const makeContext = (): ClientWidgetContext => ({
  propName: 'article',
  componentId: 'sdc.test.teaser',
  componentVersion: '1',
  jsonSchema: { type: 'string' },
  sourceTypeSettings: {},
  cardinality: 1,
  required: false,
  fieldData: {} as ClientWidgetContext['fieldData'],
});

const propSource = {} as Parameters<
  typeof transforms.entityAutocompleteTargetId
>[2];

describe('entityReferenceAutocompleteWidget codec', () => {
  const { codec } = entityReferenceAutocompleteWidget;

  it('stores the selected suggestion id as a string', () => {
    expect(codec.toModel({ id: '5', label: 'Foo' }, makeContext())).toEqual({
      resolved: '5',
    });
  });

  it('extracts the id from typed `Label (id)` text', () => {
    expect(
      codec.toModel({ id: null, label: 'Foo (5)' }, makeContext()),
    ).toEqual({ resolved: '5' });
    expect(
      codec.toModel({ id: null, label: '  Foo (5)  ' }, makeContext()),
    ).toEqual({ resolved: '5' });
  });

  it('passes typed bare ids through', () => {
    expect(codec.toModel({ id: null, label: '7' }, makeContext())).toEqual({
      resolved: '7',
    });
  });

  it('matches the entityAutocompleteTargetId transform output', () => {
    ['Foo (5)', '7', 'entity title with (parens) (12)'].forEach((text) => {
      expect(
        codec.toModel({ id: null, label: text }, makeContext())?.resolved,
      ).toEqual(transforms.entityAutocompleteTargetId(text, {}, propSource));
    });
  });

  it('returns null for empty input', () => {
    expect(codec.toModel({ id: null, label: '' }, makeContext())).toBeNull();
    expect(codec.toModel({ id: null, label: '   ' }, makeContext())).toBeNull();
    expect(codec.toModel(undefined, makeContext())).toBeNull();
  });

  it('prefers the source id over an evaluated resolved value', () => {
    expect(
      codec.fromModel('5', { id: 5, title: 'Foo' }, makeContext()),
    ).toEqual({ id: '5', label: '' });
  });

  it('falls back to the resolved value when it looks like an id', () => {
    expect(codec.fromModel(undefined, '5', makeContext())).toEqual({
      id: '5',
      label: '',
    });
  });

  it('stringifies a numeric stored id', () => {
    expect(codec.fromModel(5, undefined, makeContext())).toEqual({
      id: '5',
      label: '',
    });
  });

  it('maps an empty model value to an empty widget value', () => {
    expect(codec.fromModel(undefined, undefined, makeContext())).toEqual({
      id: null,
      label: '',
    });
  });

  it('round-trips a stored id', () => {
    const context = makeContext();
    const widgetValue = codec.fromModel('9', undefined, context);
    expect(codec.toModel(widgetValue, context)).toEqual({ resolved: '9' });
  });
});
