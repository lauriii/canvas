import { describe, expect, it } from 'vitest';

import {
  collapseColorPropsInElements,
  collectUnreconciledColorProps,
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

describe('serializePropsForServer - color transformer', () => {
  it('serializes canvas-color cssVarKey refs to UUID refs', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      accent: {
        title: 'Accent',
        type: 'string',
        $ref: 'json-schema-definitions://canvas.module/color',
      },
    };
    const colorsByCssVariable = new Map([
      [
        '--baguette-legs',
        {
          id: '88888888-8888-4888-8888-888888888888',
          name: 'Baguette Legs',
          cssVariable: '--baguette-legs',
          value: {
            colorSpace: 'srgb' as const,
            components: [0, 0, 1],
            alpha: null,
            hex: '#0000ff',
          },
          weight: 0,
        },
      ],
    ]);

    expect(
      serializePropsForServer(
        { accent: 'canvas-color:baguette-legs' },
        schemas,
        {},
        colorsByCssVariable,
      ),
    ).toEqual({
      accent: 'canvas-color:88888888-8888-4888-8888-888888888888',
    });
  });

  it('passes through free-pick CSS strings for color props', () => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      accent: {
        title: 'Accent',
        type: 'string',
        $ref: 'json-schema-definitions://canvas.module/color',
      },
    };

    expect(serializePropsForServer({ accent: '#687df7e3' }, schemas)).toEqual({
      accent: '#687df7e3',
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

  // Must stay in sync with \Drupal\canvas\TypedData\LinkUrl::getValue(): a
  // value pushed by the CLI must be stored the same way as when authored in
  // the Canvas UI. Every input is a valid `format: uri-reference` string.
  it.each([
    // Scheme-less, root-relative: `internal:` scheme.
    ['/foo', 'internal:/foo'],
    ['/foo?x=1#frag', 'internal:/foo?x=1#frag'],
    ['/', 'internal:/'],
    // Scheme-less, not root-relative: unchanged. `internal:` requires a
    // leading slash, so `internal:foo` would be rejected by the server.
    ['foo', 'foo'],
    ['foo.html?x=1', 'foo.html?x=1'],
    ['?x=1', '?x=1'],
    ['#frag', '#frag'],
    // Anything with a scheme: unchanged.
    ['https://example.com/', 'https://example.com/'],
    ['HTTPS://example.com/', 'HTTPS://example.com/'],
    ['entity:node/1', 'entity:node/1'],
    ['internal:/already', 'internal:/already'],
    ['mailto:a@example.com', 'mailto:a@example.com'],
    // Empty: unchanged.
    ['', ''],
  ])('normalizes %j to %j like LinkUrl::getValue()', (authored, stored) => {
    const schemas: Record<string, CodeComponentPropSerialized> = {
      link: { title: 'Link', type: 'string', format: 'uri-reference' },
    };

    expect(serializePropsForServer({ link: authored }, schemas)).toEqual({
      link: { uri: stored, options: [] },
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

  it('serializes color props in elements to UUID refs', () => {
    const colorMetadata: ComponentMetadata[] = [
      {
        name: 'Color Card',
        machineName: 'color-card',
        status: true,
        required: [],
        slots: {},
        props: {
          properties: {
            accent: {
              title: 'Accent',
              type: 'string',
              $ref: 'json-schema-definitions://canvas.module/color',
            },
          },
        },
      },
    ];
    const elements: AuthoredSpecElementMap = {
      node: {
        type: 'js.color-card',
        props: {
          accent: 'canvas-color:baguette-legs',
        },
      },
    };
    const remoteColors = [
      {
        id: '88888888-8888-4888-8888-888888888888',
        name: 'Baguette Legs',
        cssVariable: '--baguette-legs',
        value: {
          colorSpace: 'srgb' as const,
          components: [0, 0, 1],
          alpha: null,
          hex: '#0000ff',
        },
        weight: 0,
      },
    ];

    expect(
      serializeElementMapForServer(elements, colorMetadata, remoteColors),
    ).toEqual({
      node: {
        type: 'js.color-card',
        props: {
          accent: 'canvas-color:88888888-8888-4888-8888-888888888888',
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

  it('matches external document URLs with the document media type', () => {
    const documentSchema = {
      title: 'Document',
      type: 'object' as const,
      $ref: 'json-schema-definitions://canvas.module/document',
    };
    const value = { src: 'https://example.com/spec.pdf', filename: 'spec.pdf' };
    expect(getUnreconciledMedia(value, documentSchema)).toEqual({
      url: 'https://example.com/spec.pdf',
      mediaType: 'document',
    });
  });

  it('rejects relative document URLs', () => {
    const documentSchema = {
      title: 'Document',
      type: 'object' as const,
      $ref: 'json-schema-definitions://canvas.module/document',
    };
    const value = { src: '/ui/assets/documents/sample.pdf' };
    expect(getUnreconciledMedia(value, documentSchema)).toBeNull();
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

describe('collectUnreconciledColorProps', () => {
  it('flags color refs that do not exist on the remote brand kit', () => {
    const colorMetadata: ComponentMetadata[] = [
      {
        name: 'Color Card',
        machineName: 'color-card',
        status: true,
        required: [],
        slots: {},
        props: {
          properties: {
            accent: {
              title: 'Accent',
              type: 'string',
              $ref: 'json-schema-definitions://canvas.module/color',
            },
          },
        },
      },
    ];
    const elements: AuthoredSpecElementMap = {
      node: {
        type: 'js.color-card',
        props: {
          accent: 'canvas-color:missing-color',
        },
      },
    };

    expect(collectUnreconciledColorProps(elements, colorMetadata, [])).toEqual([
      {
        elementId: 'node',
        propName: 'accent',
        key: 'missing-color',
      },
    ]);
  });
});

describe('collapseColorPropsInElements', () => {
  const colorMetadata: ComponentMetadata[] = [
    {
      name: 'Color Card',
      machineName: 'color-card',
      status: true,
      required: [],
      slots: {},
      props: {
        properties: {
          accent: {
            title: 'Accent',
            type: 'string',
            $ref: 'json-schema-definitions://canvas.module/color',
          },
          label: {
            title: 'Label',
            type: 'string',
          },
        },
      },
    },
  ];

  it('collapses a resolved brand kit color to a canvas-color token ref', () => {
    const elements: AuthoredSpecElementMap = {
      card: {
        type: 'js.color-card',
        props: {
          accent: {
            value: {
              colorSpace: 'srgb',
              components: [0.8, 0.1, 0.1],
              hex: '#cc1a1a',
            },
            cssVariable: '--brand-red',
          },
        },
      },
    };

    const result = collapseColorPropsInElements(elements, colorMetadata);
    expect((result.card.props as Record<string, unknown>).accent).toBe(
      'canvas-color:brand-red',
    );
  });

  it('collapses a resolved free-pick color with no cssVariable to a hex string', () => {
    const elements: AuthoredSpecElementMap = {
      card: {
        type: 'js.color-card',
        props: {
          accent: {
            value: {
              colorSpace: 'srgb',
              components: [0.4, 0.78, 0.5],
              hex: '#66c880',
            },
            cssVariable: null,
          },
        },
      },
    };

    const result = collapseColorPropsInElements(elements, colorMetadata);
    expect((result.card.props as Record<string, unknown>).accent).toBe(
      '#66c880',
    );
  });

  it('collapses a resolved color with alpha to a hex+alpha string', () => {
    const elements: AuthoredSpecElementMap = {
      card: {
        type: 'js.color-card',
        props: {
          accent: {
            value: {
              colorSpace: 'srgb',
              components: [0.4, 0.78, 0.5],
              hex: '#66c880',
              alpha: 0.5,
            },
            cssVariable: null,
          },
        },
      },
    };

    const result = collapseColorPropsInElements(elements, colorMetadata);
    // alpha 0.5 → Math.round(0.5 * 255) = 128 = 0x80
    expect((result.card.props as Record<string, unknown>).accent).toBe(
      '#66c88080',
    );
  });

  it('passes through non-color props unchanged', () => {
    const elements: AuthoredSpecElementMap = {
      card: {
        type: 'js.color-card',
        props: {
          label: 'Hello',
          accent: {
            value: {
              colorSpace: 'srgb',
              components: [0.8, 0.1, 0.1],
              hex: '#cc1a1a',
            },
            cssVariable: '--brand-red',
          },
        },
      },
    };

    const result = collapseColorPropsInElements(elements, colorMetadata);
    expect((result.card.props as Record<string, unknown>).label).toBe('Hello');
  });

  it('passes through elements with no matching component metadata unchanged', () => {
    const elements: AuthoredSpecElementMap = {
      unknown: {
        type: 'js.unknown-component',
        props: {
          accent: {
            value: {
              colorSpace: 'srgb',
              components: [0, 0, 0],
              hex: '#000000',
            },
            cssVariable: '--some-color',
          },
        },
      },
    };

    const result = collapseColorPropsInElements(elements, colorMetadata);
    expect(result).toBe(elements);
  });
});
