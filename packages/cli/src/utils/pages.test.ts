import { describe, expect, it } from 'vitest';

import {
  authoredElementMapToComponentTree,
  jsonRenderSpecToAuthoredElementMap,
} from './authored-elements';
import { pageToAuthoredSpec } from './pages';

import type {
  AuthoredSpecElementMap,
  canvasTreeToSpec,
} from 'drupal-canvas/json-render-utils';
import type { Page } from '../types/Page';

describe('jsonRenderSpecToAuthoredElementMap', () => {
  it('strips the component-tree wrapper and merges children into slots.children', () => {
    const heroId = '11111111-1111-4111-8111-111111111111';
    const textId = '22222222-2222-4222-8222-222222222222';
    const buttonId = '33333333-3333-4333-8333-333333333333';

    const spec: ReturnType<typeof canvasTreeToSpec> = {
      root: 'root',
      elements: {
        root: {
          type: 'canvas:component-tree',
          props: {},
          children: [heroId],
        },
        [heroId]: {
          type: 'js.hero',
          props: { heading: 'Welcome' },
          children: [textId],
          slots: {
            actions: [buttonId],
          },
        },
        [textId]: {
          type: 'js.text',
          props: { body: 'Hello' },
        },
        [buttonId]: {
          type: 'js.button',
          props: {},
        },
      },
    };

    expect(jsonRenderSpecToAuthoredElementMap(spec)).toEqual({
      [heroId]: {
        type: 'js.hero',
        props: { heading: 'Welcome' },
        slots: {
          children: [textId],
          actions: [buttonId],
        },
      },
      [textId]: {
        type: 'js.text',
        props: { body: 'Hello' },
      },
      [buttonId]: {
        type: 'js.button',
        props: {},
      },
    });
  });

  it('omits slots when the element has no children or named slots', () => {
    const spacerId = '44444444-4444-4444-8444-444444444444';

    const spec: ReturnType<typeof canvasTreeToSpec> = {
      root: spacerId,
      elements: {
        [spacerId]: {
          type: 'js.spacer',
          props: {},
        },
      },
    };

    expect(jsonRenderSpecToAuthoredElementMap(spec)).toEqual({
      [spacerId]: {
        type: 'js.spacer',
        props: {},
      },
    });
  });
});

describe('authoredElementMapToComponentTree', () => {
  it('rebuilds parent and slot relationships from authored slots', () => {
    const heroId = '11111111-1111-4111-8111-111111111111';
    const textId = '22222222-2222-4222-8222-222222222222';
    const buttonId = '33333333-3333-4333-8333-333333333333';
    const heroVersionId = '55555555-5555-4555-8555-555555555555';
    const textVersionId = '66666666-6666-4666-8666-666666666666';

    const elements: AuthoredSpecElementMap = {
      [heroId]: {
        type: 'js.hero',
        props: { heading: 'Welcome' },
        slots: {
          content: [textId],
          actions: [buttonId],
        },
      },
      [textId]: {
        type: 'js.text',
        props: { body: 'Hello' },
      },
      [buttonId]: {
        type: 'js.button',
        props: { label: 'Read more' },
      },
    };

    expect(
      authoredElementMapToComponentTree(
        elements,
        new Map([
          ['js.hero', heroVersionId],
          ['js.text', textVersionId],
        ]),
      ),
    ).toEqual([
      {
        uuid: heroId,
        component_id: 'js.hero',
        component_version: heroVersionId,
        inputs: { heading: 'Welcome' },
        parent_uuid: null,
        slot: null,
        label: null,
      },
      {
        uuid: textId,
        component_id: 'js.text',
        component_version: textVersionId,
        inputs: { body: 'Hello' },
        parent_uuid: heroId,
        slot: 'content',
        label: null,
      },
      {
        uuid: buttonId,
        component_id: 'js.button',
        component_version: '',
        inputs: { label: 'Read more' },
        parent_uuid: heroId,
        slot: 'actions',
        label: null,
      },
    ]);
  });

  it('generates valid UUIDs for non-UUID element keys', () => {
    const uuidRegex =
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

    const elements: AuthoredSpecElementMap = {
      hero: {
        type: 'js.hero',
        props: { heading: 'Welcome' },
        slots: {
          actions: ['cta-button'],
        },
      },
      'cta-button': {
        type: 'js.button',
        props: { label: 'Click' },
      },
    };

    const tree = authoredElementMapToComponentTree(elements);
    expect(tree).toHaveLength(2);

    const hero = tree.find((c) => c.component_id === 'js.hero')!;
    const button = tree.find((c) => c.component_id === 'js.button')!;

    // Both should have valid UUIDs, not the original keys.
    expect(hero.uuid).toMatch(uuidRegex);
    expect(hero.uuid).not.toBe('hero');
    expect(button.uuid).toMatch(uuidRegex);
    expect(button.uuid).not.toBe('cta-button');

    // Parent relationship should use the generated UUID.
    expect(button.parent_uuid).toBe(hero.uuid);
    expect(button.slot).toBe('actions');
  });

  it('defaults missing props to an empty object', () => {
    const spacerId = '44444444-4444-4444-8444-444444444444';

    const elements: AuthoredSpecElementMap = {
      [spacerId]: {
        type: 'js.spacer',
      },
    };

    expect(authoredElementMapToComponentTree(elements)).toEqual([
      {
        uuid: spacerId,
        component_id: 'js.spacer',
        component_version: '',
        inputs: {},
        parent_uuid: null,
        slot: null,
        label: null,
      },
    ]);
  });
});

describe('pageToAuthoredSpec', () => {
  it('returns an empty authored spec when the page has no components', () => {
    const page: Page = {
      id: 1,
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Empty Page',
      description: '',
      status: true,
      path: '/empty',
      internalPath: '/page/1',
      autoSaveLabel: null,
      autoSavePath: null,
      links: {},
      components: [],
    };

    expect(pageToAuthoredSpec(page)).toEqual({
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Empty Page',
      path: '/empty',
      description: '',
      elements: {},
    });
  });

  it('saves empty component props returned from the api as an array as an empty object', () => {
    const page: Page = {
      id: 1,
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Test',
      description: '',
      status: true,
      path: '/test',
      internalPath: '/page/1',
      autoSaveLabel: null,
      autoSavePath: null,
      links: {},
      components: [
        {
          uuid: '48030ccd-8ed3-4d90-8866-bb0fe55dda0d',
          component_id: 'js.spacer',
          component_version: 'v1',
          parent_uuid: null,
          slot: null,
          inputs: [] as unknown as Record<string, unknown>,
          inputs_resolved: [] as unknown as Record<string, unknown>,
          label: null,
        },
      ],
    };

    const result = pageToAuthoredSpec(page) as {
      elements: AuthoredSpecElementMap;
    };

    expect(result.elements).toEqual({
      '48030ccd-8ed3-4d90-8866-bb0fe55dda0d': {
        type: 'js.spacer',
        props: {},
      },
    });
  });

  it('normalizes array inputs to empty props', () => {
    const page: Page = {
      id: 1,
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Test',
      description: '',
      status: true,
      path: '/test',
      internalPath: '/page/1',
      autoSaveLabel: null,
      autoSavePath: null,
      links: {},
      components: [
        {
          uuid: '48030ccd-8ed3-4d90-8866-bb0fe55dda0d',
          component_id: 'js.spacer',
          component_version: 'v1',
          parent_uuid: null,
          slot: null,
          inputs: [] as unknown as Record<string, unknown>,
          label: null,
        },
      ],
    };

    const result = pageToAuthoredSpec(page) as {
      elements: AuthoredSpecElementMap;
    };

    expect(result.elements).toEqual({
      '48030ccd-8ed3-4d90-8866-bb0fe55dda0d': {
        type: 'js.spacer',
        props: {},
      },
    });
  });

  it('round-trips nested components through authored spec helpers', () => {
    const heroUuid = '489ba42c-abde-47ed-ae72-c8d577154841';
    const textUuid = '01561529-b81a-43ae-afd6-7566c1bac4df';
    const buttonUuid = 'f7f47df3-b4a1-483e-bf78-01641eddec06';

    const page: Page = {
      id: 1,
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Home',
      description: 'Welcome to the homepage',
      status: true,
      path: '/home',
      internalPath: '/page/1',
      autoSaveLabel: null,
      autoSavePath: null,
      links: {},
      components: [
        {
          uuid: heroUuid,
          component_id: 'js.hero',
          component_version: 'v1',
          parent_uuid: null,
          slot: null,
          inputs: { heading: 'Welcome' },
          inputs_resolved: { heading: 'Welcome' },
          label: null,
        },
        {
          uuid: textUuid,
          component_id: 'js.text',
          component_version: 'v2',
          parent_uuid: heroUuid,
          slot: 'content',
          inputs: { body: 'Hello' },
          inputs_resolved: { body: 'Hello' },
          label: null,
        },
        {
          uuid: buttonUuid,
          component_id: 'js.button',
          component_version: 'v3',
          parent_uuid: heroUuid,
          slot: 'actions',
          inputs: { label: 'Read more' },
          inputs_resolved: { label: 'Read more' },
          label: null,
        },
      ],
    };

    const result = pageToAuthoredSpec(page) as {
      uuid: string;
      title: string;
      elements: AuthoredSpecElementMap;
    };

    expect(result).toMatchObject({
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Home',
      path: '/home',
      description: 'Welcome to the homepage',
      elements: {
        [heroUuid]: {
          slots: {
            content: [textUuid],
            actions: [buttonUuid],
          },
        },
      },
    });

    expect(
      authoredElementMapToComponentTree(
        result.elements,
        new Map([
          ['js.hero', 'v1'],
          ['js.text', 'v2'],
          ['js.button', 'v3'],
        ]),
      ),
    ).toEqual(page.components.map(({ inputs_resolved: _, ...rest }) => rest));
  });

  it('stores media provenance in _provenance when inputs_resolved contains resolved media data', () => {
    const page: Page = {
      id: 1,
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Gallery',
      description: '',
      status: true,
      path: '/gallery',
      internalPath: '/page/1',
      autoSaveLabel: null,
      autoSavePath: null,
      links: {},
      components: [
        {
          uuid: 'image-uuid',
          component_id: 'js.hero',
          component_version: 'v1',
          parent_uuid: null,
          slot: null,
          inputs: { image: { target_id: 42 } },
          inputs_resolved: {
            image: {
              src: '/sites/default/files/example.jpg',
              alt: 'Example image',
              width: 1200,
              height: 800,
            },
          },
          label: null,
        },
      ],
    };

    expect(pageToAuthoredSpec(page)).toEqual({
      uuid: '27a539f5-2dd0-471a-a364-8fee7a024a73',
      title: 'Gallery',
      path: '/gallery',
      description: '',
      elements: {
        'image-uuid': {
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
      },
    });
  });
});
