import { describe, expect, it } from 'vitest';

import {
  normalizeMockSpecEntry,
  parseMockSpecArray,
  parseMockSpecMetadataArray,
  validateMockSpecArray,
} from './mock-spec';

describe('mock-spec', () => {
  it('normalizes props-format mock entries with an inferred component name', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Centered',
            props: {
              text: 'Hello world',
            },
          },
        ],
      },
      '/tmp/components/heading/mocks.json',
      {
        componentName: 'heading',
        componentNames: ['heading'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks).toHaveLength(1);
    expect(result.mocks[0]).toEqual({
      name: 'Centered',
      spec: {
        root: 'canvas:mock-root',
        elements: {
          'canvas:mock-root': {
            type: 'heading',
            props: {
              text: 'Hello world',
            },
          },
        },
      },
    });
  });

  it('merges only required example props into shorthand mock props', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Light',
            props: {
              darkVariant: false,
            },
          },
        ],
      },
      '/tmp/components/hero/mocks.json',
      {
        componentName: 'hero',
        componentNames: ['hero'],
        componentExampleProps: {
          title: 'Example title',
          backgroundColor: 'crust',
          darkVariant: true,
        },
        componentRequiredPropNames: ['title', 'darkVariant'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks[0].spec.elements['canvas:mock-root']).toEqual({
      type: 'hero',
      props: {
        title: 'Example title',
        darkVariant: false,
      },
    });
  });

  it('normalizes props-and-slots mock entries into a synthetic root element', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Featured',
            props: {
              featured: true,
            },
            slots: {
              header: ['card-header'],
            },
            elements: {
              'card-header': {
                type: 'heading',
                props: {
                  text: 'Featured article',
                },
              },
            },
          },
        ],
      },
      '/tmp/components/card/card.mocks.json',
      {
        componentName: 'card',
        componentNames: ['card', 'heading'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks[0].spec).toEqual({
      root: 'canvas:mock-root',
      elements: {
        'canvas:mock-root': {
          type: 'card',
          props: {
            featured: true,
          },
          slots: {
            header: ['card-header'],
          },
        },
        'card-header': {
          type: 'heading',
          props: {
            text: 'Featured article',
          },
        },
      },
    });
  });

  it('preserves advanced-format mock entries', () => {
    const normalized = normalizeMockSpecEntry({
      name: 'In grid',
      root: 'card-grid',
      elements: {
        'card-grid': {
          type: 'grid',
          slots: {
            items: ['card-1'],
          },
        },
        'card-1': {
          type: 'card',
          props: {
            featured: true,
          },
        },
      },
    });

    expect(normalized).toEqual({
      name: 'In grid',
      spec: {
        root: 'card-grid',
        elements: {
          'card-grid': {
            type: 'grid',
            slots: {
              items: ['card-1'],
            },
          },
          'card-1': {
            type: 'card',
            props: {
              featured: true,
            },
          },
        },
      },
    });
  });

  it('merges example props into inferred component elements in advanced format', () => {
    const normalized = normalizeMockSpecEntry(
      {
        name: 'In section',
        root: 'pricing-table-in-section',
        elements: {
          'pricing-table-in-section': {
            type: 'section',
            props: {
              backgroundColor: 'base',
            },
            slots: {
              content: ['pricing-table-default'],
            },
          },
          'pricing-table-default': {
            type: 'pricing-table',
            props: {},
          },
        },
      },
      {
        componentName: 'pricing-table',
        componentExampleProps: {
          entryTierName: 'Starter',
          buttonLabel: 'Choose {tier}',
        },
        componentRequiredPropNames: ['entryTierName'],
      },
    );

    expect(normalized.spec.elements['pricing-table-default']).toEqual({
      type: 'pricing-table',
      props: {
        entryTierName: 'Starter',
      },
    });
    expect(normalized.spec.elements['pricing-table-in-section']).toEqual({
      type: 'section',
      props: {
        backgroundColor: 'base',
      },
      slots: {
        content: ['pricing-table-default'],
      },
    });
  });

  it('returns a warning for files without a mocks array', () => {
    const result = validateMockSpecArray(
      {
        $schema: 'https://example.com/component-mocks.schema.json',
      },
      '/tmp/components/heading/mocks.json',
    );

    expect(result.mocks).toEqual([]);
    expect(result.specs).toEqual([]);
    expect(result.warnings).toEqual([
      {
        code: 'invalid_mock_spec_file',
        message:
          'Mock file must include a mocks array: /tmp/components/heading/mocks.json',
        path: '/tmp/components/heading/mocks.json#mocks',
      },
    ]);
  });

  it('extracts mock names for discovery metadata', () => {
    const result = parseMockSpecMetadataArray(
      {
        mocks: [
          { name: 'Centered', props: {} },
          {
            name: 'Featured',
            root: 'card',
            elements: { card: { type: 'card' } },
          },
        ],
      },
      '/tmp/components/card/card.mocks.json',
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks).toEqual([{ name: 'Centered' }, { name: 'Featured' }]);
  });

  it('rejects shorthand entries without an inferred component name', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Centered',
            props: {
              text: 'Hello world',
            },
          },
        ],
      },
      '/tmp/components/heading/mocks.json',
    );

    expect(result.mocks).toEqual([]);
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].message).toContain(
      'Shorthand mock entries require an inferred component name.',
    );
  });

  it('rejects entries that do not match a supported authored format', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Broken',
            slots: {
              header: ['card-header'],
            },
          },
        ],
      },
      '/tmp/components/card/card.mocks.json',
      {
        componentName: 'card',
      },
    );

    expect(result.mocks).toEqual([]);
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].message).toContain('Expected one of these shapes');
  });

  it('keeps props-and-slots entries even when slot references are unresolved', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Broken',
            props: {
              featured: true,
            },
            slots: {
              header: ['missing-header'],
            },
            elements: {
              'card-footer': {
                type: 'button',
              },
            },
          },
        ],
      },
      '/tmp/components/card/card.mocks.json',
      {
        componentName: 'card',
        componentNames: ['card', 'button'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks).toHaveLength(1);
  });

  it('keeps unknown advanced-format component types for later render-time handling', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'Broken',
            root: 'root',
            elements: {
              root: {
                type: 'unknown-component',
                props: {},
              },
            },
          },
        ],
      },
      '/tmp/components/card/mocks.json',
      { componentNames: ['card'] },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks).toHaveLength(1);
  });

  it('merges example props during advanced-format parsing', () => {
    const result = parseMockSpecArray(
      {
        mocks: [
          {
            name: 'In section',
            root: 'pricing-table-in-section',
            elements: {
              'pricing-table-in-section': {
                type: 'section',
                props: {
                  backgroundColor: 'base',
                },
                slots: {
                  content: ['pricing-table-default'],
                },
              },
              'pricing-table-default': {
                type: 'pricing-table',
                props: {},
              },
            },
          },
        ],
      },
      '/tmp/components/pricing-table/mocks.json',
      {
        componentName: 'pricing-table',
        componentNames: ['pricing-table', 'section'],
        componentExampleProps: {
          entryTierName: 'Starter',
          buttonLabel: 'Choose {tier}',
        },
        componentRequiredPropNames: ['entryTierName'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks[0].spec.elements['pricing-table-default']).toEqual({
      type: 'pricing-table',
      props: {
        entryTierName: 'Starter',
      },
    });
  });

  it('accepts an optional top-level $schema key', () => {
    const result = parseMockSpecArray(
      {
        $schema: 'https://example.com/component-mocks.schema.json',
        mocks: [
          {
            name: 'Centered',
            props: {
              text: 'Hello world',
            },
          },
        ],
      },
      '/tmp/components/heading/mocks.json',
      {
        componentName: 'heading',
        componentNames: ['heading'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks).toHaveLength(1);
  });

  it('still accepts legacy top-level arrays during the transition', () => {
    const result = parseMockSpecArray(
      [
        {
          name: 'Centered',
          props: {
            text: 'Hello world',
          },
        },
      ],
      '/tmp/components/heading/mocks.json',
      {
        componentName: 'heading',
        componentNames: ['heading'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.mocks).toHaveLength(1);
  });
});
