import { describe, expect, it } from 'vitest';

import transforms from '@/utils/transforms';

import { linkDefaultWidget } from './LinkWidget';

import type { ClientWidgetContext } from '../types';

const makeContext = (title: 0 | 1 | 2): ClientWidgetContext => ({
  propName: 'cta',
  componentId: 'sdc.test.hero',
  componentVersion: '1',
  jsonSchema: { type: 'string' },
  sourceTypeSettings: { instance: { title } },
  cardinality: 1,
  required: false,
  fieldData: {} as ClientWidgetContext['fieldData'],
});

const makePropSource = (title: 0 | 1 | 2) =>
  ({
    sourceTypeSettings: { instance: { title } },
  }) as Parameters<typeof transforms.link>[2];

describe('linkDefaultWidget codec', () => {
  const { codec } = linkDefaultWidget;

  it('stores the uri string alone when the title is disabled', () => {
    expect(
      codec.toModel({ uri: 'https://example.com', title: '' }, makeContext(0)),
    ).toEqual({ resolved: 'https://example.com' });
  });

  it('drops a title typed while the title sub-field is disabled', () => {
    expect(
      codec.toModel(
        { uri: 'https://example.com', title: 'Ignored' },
        makeContext(0),
      ),
    ).toEqual({ resolved: 'https://example.com' });
  });

  it('stores a {uri, title} object when the title is optional', () => {
    expect(codec.toModel({ uri: '/about', title: '' }, makeContext(1))).toEqual(
      {
        resolved: { uri: '/about', title: '' },
      },
    );
  });

  it('stores a {uri, title} object when the title is required', () => {
    expect(
      codec.toModel(
        { uri: 'https://example.com', title: 'Click me' },
        makeContext(2),
      ),
    ).toEqual({ resolved: { uri: 'https://example.com', title: 'Click me' } });
  });

  it('resolves autocomplete-style input to an entity uri', () => {
    expect(
      codec.toModel({ uri: 'A node title (3)', title: '' }, makeContext(0)),
    ).toEqual({ resolved: 'entity:node/3' });
    expect(
      codec.toModel(
        { uri: 'A node title (3)', title: 'Click me' },
        makeContext(2),
      ),
    ).toEqual({ resolved: { uri: 'entity:node/3', title: 'Click me' } });
  });

  it('keeps an already-resolved entity uri as-is', () => {
    expect(
      codec.toModel({ uri: 'entity:node/42', title: '' }, makeContext(0)),
    ).toEqual({ resolved: 'entity:node/42' });
  });

  it('matches the link transform output', () => {
    (
      [
        [0, { uri: 'A node title (3)', title: '' }],
        [1, { uri: '/about', title: '' }],
        [2, { uri: 'A node title (3)', title: 'Click me' }],
      ] as const
    ).forEach(([title, record]) => {
      expect(
        codec.toModel({ ...record }, makeContext(title))?.resolved,
      ).toEqual(transforms.link([{ ...record }], {}, makePropSource(title)));
    });
  });

  it('returns null when the uri is empty', () => {
    expect(codec.toModel({ uri: '', title: '' }, makeContext(0))).toBeNull();
    expect(
      codec.toModel({ uri: '', title: 'Title only' }, makeContext(2)),
    ).toBeNull();
    expect(codec.toModel(undefined, makeContext(0))).toBeNull();
  });

  it('parses a stored uri string', () => {
    expect(
      codec.fromModel(undefined, 'https://example.com', makeContext(0)),
    ).toEqual({ uri: 'https://example.com', title: '' });
  });

  it('parses a stored {uri, title} object', () => {
    expect(
      codec.fromModel(
        undefined,
        { uri: 'entity:node/123', title: 'Some page' },
        makeContext(2),
      ),
    ).toEqual({ uri: 'entity:node/123', title: 'Some page' });
  });

  it('round-trips an entity uri without re-parsing it', () => {
    const context = makeContext(0);
    const widgetValue = codec.fromModel(undefined, 'entity:node/123', context);
    expect(widgetValue).toEqual({ uri: 'entity:node/123', title: '' });
    expect(codec.toModel(widgetValue, context)).toEqual({
      resolved: 'entity:node/123',
    });
  });

  it('maps an empty model value to empty inputs', () => {
    expect(codec.fromModel(undefined, undefined, makeContext(2))).toEqual({
      uri: '',
      title: '',
    });
  });
});
