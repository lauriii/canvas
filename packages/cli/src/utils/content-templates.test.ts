import { describe, expect, it } from 'vitest';

import {
  contentTemplateToAuthored,
  serverPropToAuthored,
} from './content-templates';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { ContentTemplate } from '../types/ContentTemplate';

describe('serverPropToAuthored', () => {
  it('passes simple entity-field prop sources through verbatim', () => {
    const propSource = {
      sourceType: 'entity-field',
      expression: 'ℹ︎␜entity:node:article␝title␞␟value',
    };
    expect(serverPropToAuthored(propSource)).toEqual(propSource);
  });

  it('passes complex FieldObjectPropsExpression through verbatim', () => {
    const propSource = {
      sourceType: 'entity-field',
      expression:
        'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
    };
    expect(serverPropToAuthored(propSource)).toEqual(propSource);
  });

  it('passes ReferenceFieldPropExpression through verbatim', () => {
    const propSource = {
      sourceType: 'entity-field',
      expression: 'ℹ︎␜entity:node:article␝uid␞␟entity␜␜entity:user␝name␞␟value',
    };
    expect(serverPropToAuthored(propSource)).toEqual(propSource);
  });

  it('passes host-entity-url prop sources through unchanged', () => {
    const propSource = { sourceType: 'host-entity-url', absolute: false };
    expect(serverPropToAuthored(propSource)).toEqual(propSource);
  });

  it('passes adapter prop sources through verbatim, including nested parameters', () => {
    const propSource = {
      sourceType: 'adapter:image_apply_style',
      adapterInputs: {
        image: {
          sourceType: 'entity-field',
          expression: 'ℹ︎␜entity:node:article␝field_image␞␟value',
        },
        imageStyle: { sourceType: 'static:field_item:string', value: 'large' },
      },
    };
    expect(serverPropToAuthored(propSource)).toEqual(propSource);
  });

  it('unwraps static prop sources to their inner value', () => {
    expect(
      serverPropToAuthored({
        sourceType: 'static:field_item:string',
        value: 'hello',
      }),
    ).toBe('hello');
  });

  it('normalizes the deprecated `dynamic` alias to `entity-field`', () => {
    const result = serverPropToAuthored({
      sourceType: 'dynamic',
      expression: 'ℹ︎␜entity:node:article␝title␞␟value',
    });
    expect(result).toEqual({
      sourceType: 'entity-field',
      expression: 'ℹ︎␜entity:node:article␝title␞␟value',
    });
  });

  it('passes plain values through unchanged', () => {
    expect(serverPropToAuthored('hello')).toBe('hello');
    expect(serverPropToAuthored(42)).toBe(42);
    expect(serverPropToAuthored(null)).toBe(null);
  });

  it('passes literal records without a sourceType key through unchanged', () => {
    const literal = { color: 'red', size: 'lg' };
    expect(serverPropToAuthored(literal)).toEqual(literal);
  });
});

describe('serverPropToAuthored roundtrip', () => {
  it('preserves entity-field prop sources', () => {
    const original = {
      sourceType: 'entity-field',
      expression: 'ℹ︎␜entity:node:article␝title␞␟value',
    };
    expect(serverPropToAuthored(original)).toEqual(original);
  });

  it('preserves complex FieldObjectPropsExpression', () => {
    const original = {
      sourceType: 'entity-field',
      expression:
        'ℹ︎␜entity:node:article␝field_image␞␟{src↠src_with_alternate_widths,alt↠alt,width↠width,height↠height}',
    };
    expect(serverPropToAuthored(original)).toEqual(original);
  });

  it('preserves host-entity-url prop sources', () => {
    const original = { sourceType: 'host-entity-url', absolute: false };
    expect(serverPropToAuthored(original)).toEqual(original);
  });

  it('preserves adapter prop sources with nested entity-field inputs', () => {
    const original = {
      sourceType: 'adapter:image_apply_style',
      adapterInputs: {
        image: {
          sourceType: 'entity-field',
          expression: 'ℹ︎␜entity:node:article␝field_image␞␟value',
        },
        imageStyle: { sourceType: 'static:field_item:string', value: 'large' },
      },
    };
    expect(serverPropToAuthored(original)).toEqual(original);
  });
});

describe('contentTemplateToAuthored', () => {
  it('preserves authored content entity reference inputs instead of resolved values', () => {
    const template: ContentTemplate = {
      id: 'node.article.full',
      label: 'Article full',
      status: true,
      entityType: 'node',
      bundle: 'article',
      viewMode: 'full',
      component_tree: [
        {
          uuid: '11111111-1111-4111-8111-111111111111',
          parent_uuid: null,
          slot: null,
          component_id: 'js.article-card',
          component_version: 'v1',
          inputs: {
            article: { target_id: '42' },
          },
          inputs_resolved: {
            article: {
              __type: 'article',
              title: 'Resolved article title',
            },
          },
          label: null,
        },
      ],
    };

    expect(contentTemplateToAuthored(template).elements).toEqual({
      '11111111-1111-4111-8111-111111111111': {
        type: 'js.article-card',
        props: {
          article: { target_id: '42' },
        },
      },
    });
  });

  it('writes the page variant selection only when the server has one', () => {
    const template: ContentTemplate = {
      id: 'node.article.full',
      label: 'Article full',
      status: true,
      entityType: 'node',
      bundle: 'article',
      viewMode: 'full',
      component_tree: [],
    };

    expect(contentTemplateToAuthored(template)).not.toHaveProperty(
      'pageVariant',
    );
    expect(
      contentTemplateToAuthored({ ...template, pageVariant: null }),
    ).not.toHaveProperty('pageVariant');
    expect(
      contentTemplateToAuthored({ ...template, pageVariant: 'marketing' })
        .pageVariant,
    ).toBe('marketing');
  });

  it('collapses a resolved color object in inputs to a canvas-color token ref when componentMetadata is supplied', () => {
    // Defensive: if inputs carries a resolved color object (e.g. {value, cssVariable}),
    // collapseColorPropsInElements converts it to the canonical authored form.
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

    const template: ContentTemplate = {
      id: 'node.memo.full',
      label: 'Memo full',
      status: true,
      entityType: 'node',
      bundle: 'memo',
      viewMode: 'full',
      component_tree: [
        {
          uuid: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
          parent_uuid: null,
          slot: null,
          component_id: 'js.color-card',
          component_version: 'v1',
          inputs: {
            accent: {
              value: {
                colorSpace: 'srgb',
                components: [0.8, 0.1, 0.1],
                hex: '#cc1a1a',
              },
              cssVariable: '--brand-red',
            },
          },
          label: null,
        },
      ],
    };

    const authored = contentTemplateToAuthored(template, colorMetadata);
    expect(
      (
        authored.elements['ffffffff-ffff-4fff-8fff-ffffffffffff']
          .props as Record<string, unknown>
      ).accent,
    ).toBe('canvas-color:brand-red');
  });
});
