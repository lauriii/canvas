import { describe, expect, it } from 'vitest';

import { pageVariantToAuthoredSpec } from './page-variants';
import { serializeElementMapForServer } from './prop-transforms';
import {
  buildElementsValidationContext,
  validateElements,
} from './validate-elements';

import type { ComponentMetadata } from '@drupal-canvas/discovery';
import type { PageVariant } from '../types/PageVariant';

describe('pageVariantToAuthoredSpec', () => {
  it('pulls resolved props that validate and serialize back to stored inputs', () => {
    const metadata: ComponentMetadata = {
      name: 'Banner',
      machineName: 'banner',
      status: true,
      props: {
        properties: {
          image: {
            title: 'Image',
            type: 'object',
            $ref: 'json-schema-definitions://canvas.module/image',
          },
          text: {
            title: 'Text',
            type: 'string',
            contentMediaType: 'text/html',
          },
          link: { title: 'Link', type: 'string', format: 'uri-reference' },
        },
      },
      required: [],
      slots: {},
    };
    const inputs = {
      image: { target_id: 42 },
      text: { value: '<p>Welcome</p>', format: 'canvas_html_block' },
      link: { uri: 'internal:/about', options: [] },
    };
    const resolved = {
      image: {
        src: '/sites/default/files/banner.jpg',
        alt: 'Banner',
        width: 1200,
        height: 800,
      },
      text: '<p>Welcome</p>',
      link: '/about',
    };
    const id = 'c264f8d9-6657-4e54-8431-29664bf28add';
    const variant: PageVariant = {
      id: 'landing_page_variant',
      label: 'Landing page',
      status: true,
      component_tree: [
        {
          uuid: id,
          component_id: 'js.banner',
          component_version: 'v1',
          parent_uuid: null,
          slot: null,
          label: null,
          inputs,
          inputs_resolved: resolved,
        },
      ],
    };

    const { elements } = pageVariantToAuthoredSpec(variant, false);
    expect(elements[id]).toEqual({
      type: 'js.banner',
      props: resolved,
      _provenance: { image: { target_id: 42 } },
    });
    expect(
      validateElements(elements, buildElementsValidationContext([metadata])),
    ).toEqual({ success: true });
    expect(
      serializeElementMapForServer(elements, [metadata])[id].props,
    ).toEqual(inputs);
  });

  it('returns an empty elements map when the variant has no components', () => {
    const variant: PageVariant = {
      id: 'marketing',
      label: 'Marketing',
      status: true,
      component_tree: [],
    };

    const spec = pageVariantToAuthoredSpec(variant, false);

    expect(spec).toEqual({
      label: 'Marketing',
      status: true,
      elements: {},
    });
  });

  it('preserves label, description, status, and the default flag', () => {
    const variant: PageVariant = {
      id: 'marketing',
      label: 'Marketing',
      description: 'Marketing pages.',
      status: false,
      component_tree: [],
    };

    const spec = pageVariantToAuthoredSpec(variant, true);

    expect(spec).toEqual({
      label: 'Marketing',
      description: 'Marketing pages.',
      status: false,
      default: true,
      elements: {},
    });
  });

  it('produces an authored element map for non-empty trees, marker included', () => {
    const variant: PageVariant = {
      id: 'marketing',
      label: 'Marketing',
      status: true,
      component_tree: [
        {
          uuid: '11111111-1111-4111-8111-111111111111',
          parent_uuid: null,
          slot: null,
          component_id: 'js.logo',
          component_version: 'v1',
          inputs: { linkToFrontPage: true },
          label: null,
        },
        {
          uuid: '22222222-2222-4222-8222-222222222222',
          parent_uuid: null,
          slot: null,
          component_id: 'marker.page_content',
          component_version: 'v1',
          inputs: {},
          label: null,
        },
      ],
    };

    const spec = pageVariantToAuthoredSpec(variant, false);

    expect(spec.elements['11111111-1111-4111-8111-111111111111'].type).toBe(
      'js.logo',
    );
    expect(spec.elements['22222222-2222-4222-8222-222222222222'].type).toBe(
      'marker.page_content',
    );
    expect(spec).not.toHaveProperty('default');
  });

  it('treats components with missing parent_uuid/slot/label as root', () => {
    // The PageVariant config schema omits these keys when null, so the
    // server returns them as undefined. canvasTreeToSpec requires explicit
    // null to recognize root components.
    const variant: PageVariant = {
      id: 'marketing',
      label: 'Marketing',
      status: true,
      component_tree: [
        {
          uuid: '9c1d5586-fdec-496a-84d1-071bdf995556',
          component_id: 'js.logo',
          component_version: 'v1',
          inputs: {},
        } as PageVariant['component_tree'][number],
      ],
    };

    const spec = pageVariantToAuthoredSpec(variant, false);

    expect(spec.elements['9c1d5586-fdec-496a-84d1-071bdf995556']).toBeDefined();
  });

  it('preserves authored content entity reference inputs instead of resolved values', () => {
    const variant: PageVariant = {
      id: 'marketing',
      label: 'Marketing',
      status: true,
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

    expect(pageVariantToAuthoredSpec(variant, false).elements).toEqual({
      '11111111-1111-4111-8111-111111111111': {
        type: 'js.article-card',
        props: {
          article: { target_id: '42' },
        },
      },
    });
  });
});
