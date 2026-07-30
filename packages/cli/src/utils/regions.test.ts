import { describe, expect, it } from 'vitest';

import { authoredRegionToPayload, regionToAuthoredSpec } from './regions';

import type { Region } from '../types/Region';

describe('regionToAuthoredSpec', () => {
  it('returns an empty elements map when the region has no components', () => {
    const region: Region = {
      id: 'olivero.header',
      theme: 'olivero',
      region: 'header',
      status: true,
      component_tree: [],
    };

    const spec = regionToAuthoredSpec(region);

    expect(spec).toEqual({
      status: true,
      elements: {},
    });
  });

  it('preserves status and produces an authored element map for non-empty trees', () => {
    const region: Region = {
      id: 'olivero.footer',
      theme: 'olivero',
      region: 'footer',
      status: false,
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
      ],
    };

    const spec = regionToAuthoredSpec(region);

    expect(spec.status).toBe(false);
    expect(Object.keys(spec.elements)).toContain(
      '11111111-1111-4111-8111-111111111111',
    );
    expect(spec.elements['11111111-1111-4111-8111-111111111111'].type).toBe(
      'js.logo',
    );
    expect(spec.elements['11111111-1111-4111-8111-111111111111'].props).toEqual(
      {
        linkToFrontPage: true,
      },
    );
  });

  it('treats components with missing parent_uuid/slot/label as root', () => {
    // The PageRegion config schema omits these keys when null, so the
    // server returns them as undefined. canvasTreeToSpec requires explicit
    // null to recognize root components.
    const region: Region = {
      id: 'olivero.header',
      theme: 'olivero',
      region: 'header',
      status: true,
      component_tree: [
        {
          uuid: '9c1d5586-fdec-496a-84d1-071bdf995556',
          component_id: 'js.logo',
          component_version: 'v1',
          inputs: {},
        } as Region['component_tree'][number],
      ],
    };

    const spec = regionToAuthoredSpec(region);

    expect(spec.elements['9c1d5586-fdec-496a-84d1-071bdf995556']).toBeDefined();
  });

  it('uses resolved props while preserving references needed for push', () => {
    const region: Region = {
      id: 'olivero.header',
      theme: 'olivero',
      region: 'header',
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
            image: { target_id: 21 },
            link: { uri: 'internal:/contact' },
          },
          inputs_resolved: {
            article: {
              __type: 'article',
              title: 'Resolved article title',
            },
            image: {
              src: '/sites/default/files/header.jpg',
              alt: 'Header',
              width: 1200,
              height: 400,
            },
            link: {
              url: '/contact',
            },
          },
          label: null,
        },
      ],
    };

    expect(regionToAuthoredSpec(region).elements).toEqual({
      '11111111-1111-4111-8111-111111111111': {
        type: 'js.article-card',
        props: {
          article: { target_id: '42' },
          image: {
            src: '/sites/default/files/header.jpg',
            alt: 'Header',
            width: 1200,
            height: 400,
          },
          link: {
            url: '/contact',
          },
        },
        _provenance: {
          image: {
            target_id: 21,
          },
        },
      },
    });
  });
});

describe('authoredRegionToPayload', () => {
  it('builds a payload with region from the caller and omits theme', () => {
    const componentTree = [
      {
        uuid: 'aa',
        parent_uuid: null,
        slot: null,
        component_id: 'js.header',
        component_version: 'v1',
        inputs: {},
        label: null,
      },
    ];

    const payload = authoredRegionToPayload(
      { status: true, elements: {} },
      'header',
      componentTree,
    );

    expect(payload).toEqual({
      region: 'header',
      status: true,
      component_tree: componentTree,
    });
    expect(payload).not.toHaveProperty('theme');
  });

  it('defaults `status` to true when omitted from the authored spec', () => {
    const payload = authoredRegionToPayload(
      { elements: {} },
      'sidebar_first',
      [],
    );

    expect(payload.status).toBe(true);
  });
});
