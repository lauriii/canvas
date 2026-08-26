import { describe, expect, it } from 'vitest';

import { pageVariantToAuthoredSpec } from './page-variants';

import type { PageVariant } from '../types/PageVariant';

describe('pageVariantToAuthoredSpec', () => {
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
