import { describe, expect, it } from 'vitest';

import {
  serializeElementMapForServer,
  serializePropsForServer,
} from './prop-transforms';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { CodeComponentPropSerialized } from '@drupal-canvas/ui/types/CodeComponent';

describe('serializePropsForServer — formatted text transformer', () => {
  it('wraps formatted text string into { value, format } for block context', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      body: {
        title: 'Body',
        type: 'string',
        contentMediaType: 'text/html',
        'x-formatting-context': 'block',
      },
    };

    expect(serializePropsForServer({ body: '<p>Hello</p>' }, schemas)).toEqual({
      body: { value: '<p>Hello</p>', format: 'canvas_html_block' },
    });
  });

  it('wraps formatted text string into { value, format } for inline context', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      heading: {
        title: 'Heading',
        type: 'string',
        contentMediaType: 'text/html',
        'x-formatting-context': 'inline',
      },
    };

    expect(
      serializePropsForServer(
        { heading: 'This is <strong>bold</strong>' },
        schemas,
      ),
    ).toEqual({
      heading: {
        value: 'This is <strong>bold</strong>',
        format: 'canvas_html_inline',
      },
    });
  });

  it('defaults to block format when x-formatting-context is absent', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      content: {
        title: 'Content',
        type: 'string',
        contentMediaType: 'text/html',
      },
    };

    expect(
      serializePropsForServer({ content: '<p>Text</p>' }, schemas),
    ).toEqual({
      content: { value: '<p>Text</p>', format: 'canvas_html_block' },
    });
  });
});

describe('serializePropsForServer — passthrough', () => {
  it('passes through props that have no matching transformer', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      title: { title: 'Title', type: 'string' },
      count: { title: 'Count', type: 'number' },
    };

    expect(
      serializePropsForServer({ title: 'Hello', count: 42 }, schemas),
    ).toEqual({ title: 'Hello', count: 42 });
  });

  it('passes through props that have no schema entry', () => {
    expect(serializePropsForServer({ unknown: 'value' }, {})).toEqual({
      unknown: 'value',
    });
  });
});

describe('serializePropsForServer — link transformer', () => {
  it('wraps absolute URI into { uri, options }', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      link: { title: 'Link', type: 'string', format: 'uri' },
    };

    expect(
      serializePropsForServer({ link: 'https://example.com' }, schemas),
    ).toEqual({
      link: { uri: 'https://example.com', options: [] },
    });
  });

  it('wraps relative path with internal: prefix for uri-reference', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      link: { title: 'Link', type: 'string', format: 'uri-reference' },
    };

    expect(serializePropsForServer({ link: '/about-us' }, schemas)).toEqual({
      link: { uri: 'internal:/about-us', options: [] },
    });
  });

  it('does not add internal: prefix to absolute URLs in uri-reference', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      link: { title: 'Link', type: 'string', format: 'uri-reference' },
    };

    expect(
      serializePropsForServer({ link: 'https://drupal.org' }, schemas),
    ).toEqual({
      link: { uri: 'https://drupal.org', options: [] },
    });
  });

  it('handles iri and iri-reference formats', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      abs: { title: 'IRI', type: 'string', format: 'iri' },
      rel: { title: 'IRI Ref', type: 'string', format: 'iri-reference' },
    };

    expect(
      serializePropsForServer(
        { abs: 'https://iri.example.com', rel: '/iri-path' },
        schemas,
      ),
    ).toEqual({
      abs: { uri: 'https://iri.example.com', options: [] },
      rel: { uri: 'internal:/iri-path', options: [] },
    });
  });
});

describe('serializeElementMapForServer', () => {
  it('serializes props for elements with known schemas', () => {
    const metadata: ComponentMetadata[] = [
      {
        name: 'Hero',
        machineName: 'hero',
        status: true,
        required: [],
        slots: {},
        props: {
          properties: {
            heading: {
              title: 'Heading',
              type: 'string' as const,
            },
            body: {
              title: 'Body',
              type: 'string' as const,
              contentMediaType: 'text/html',
              'x-formatting-context': 'block',
            },
          },
        },
      },
    ];

    const elements = {
      'elem-1': {
        type: 'js.hero',
        props: {
          heading: 'Welcome',
          body: '<p>Hello world</p>',
        },
      },
    };

    expect(serializeElementMapForServer(elements, metadata)).toEqual({
      'elem-1': {
        type: 'js.hero',
        props: {
          heading: 'Welcome',
          body: { value: '<p>Hello world</p>', format: 'canvas_html_block' },
        },
      },
    });
  });

  it('passes through elements with unknown component types', () => {
    const elements = {
      'elem-1': {
        type: 'js.unknown',
        props: { title: 'Hello' },
      },
    };

    expect(serializeElementMapForServer(elements, [])).toEqual(elements);
  });
});
