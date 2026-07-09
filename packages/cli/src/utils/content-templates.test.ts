import { describe, expect, it } from 'vitest';

import {
  contentTemplateToAuthored,
  serverPropToAuthored,
} from './content-templates';

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
});
