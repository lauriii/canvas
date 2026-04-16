import { describe, expect, it } from 'vitest';

import {
  collectUnreconciledMediaProps,
  getUnreconciledMedia,
  serializeElementMapForServer,
  serializePropsForServer,
} from './prop-transforms';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { CodeComponentPropSerialized } from '@drupal-canvas/ui/types/CodeComponent';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';

const metadata: ComponentMetadata[] = [
  {
    name: 'hero',
    machineName: 'hero',
    status: true,
    required: [],
    slots: {},
    props: {
      properties: {
        image: {
          title: 'Image',
          type: 'object',
          $ref: 'json-schema-definitions://canvas.module/image',
        },
      },
    },
  },
];

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
    const heroMetadata: ComponentMetadata[] = [
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

    expect(serializeElementMapForServer(elements, heroMetadata)).toEqual({
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

  it('uses _provenance for image props during push serialization', () => {
    const elements = {
      hero: {
        type: 'js.hero',
        props: {
          image: {
            src: '/sites/default/files/example.jpg',
            alt: 'Example image',
            width: 1200,
            height: 800,
          },
        },
        _provenance: {
          image: {
            target_id: 42,
          },
        },
      },
    } as AuthoredSpecElementMap;

    expect(serializeElementMapForServer(elements, metadata)).toEqual({
      hero: {
        type: 'js.hero',
        props: {
          image: {
            target_id: 42,
          },
        },
        _provenance: {
          image: {
            target_id: 42,
          },
        },
      },
    });
  });
});

describe('getUnreconciledMedia', () => {
  const imageSchema = {
    title: 'Image',
    type: 'object' as const,
    $ref: 'json-schema-definitions://canvas.module/image',
  };

  it('matches data URLs', () => {
    const value = { src: 'data:image/svg+xml;base64,PHN2Zz4=' };
    expect(getUnreconciledMedia(value, imageSchema)).toEqual({
      url: 'data:image/svg+xml;base64,PHN2Zz4=',
      mediaType: 'image',
    });
  });

  it('rejects relative URLs', () => {
    const value = { src: './images/photo.jpg' };
    expect(getUnreconciledMedia(value, imageSchema)).toBeNull();
  });

  it('rejects empty src', () => {
    const value = { src: '' };
    expect(getUnreconciledMedia(value, imageSchema)).toBeNull();
  });
});

describe('collectUnreconciledMediaProps', () => {
  it('collects data URL media props', () => {
    const elements = {
      logo: {
        type: 'js.hero',
        props: {
          image: {
            src: 'data:image/png;base64,abc123',
            alt: 'Logo',
          },
        },
      },
    } as AuthoredSpecElementMap;

    expect(collectUnreconciledMediaProps(elements, metadata)).toEqual([
      {
        elementId: 'logo',
        propName: 'image',
        src: 'data:image/png;base64,abc123',
        mediaType: 'image',
      },
    ]);
  });

  it('flags external media URLs even when provenance is already present', () => {
    const elements = {
      hero: {
        type: 'js.hero',
        props: {
          image: {
            src: 'https://example.com/example.jpg',
            alt: 'Example image',
          },
        },
        _provenance: {
          image: {
            target_id: 42,
          },
        },
      },
    } as AuthoredSpecElementMap;

    expect(collectUnreconciledMediaProps(elements, metadata)).toEqual([
      {
        elementId: 'hero',
        propName: 'image',
        src: 'https://example.com/example.jpg',
        mediaType: 'image',
      },
    ]);
  });
});
