import { describe, expect, it } from 'vitest';

import {
  normalizePageSpec,
  parsePageSpec,
  parsePageSpecMetadata,
} from './page-spec';

describe('page-spec', () => {
  it('normalizes authored page specs and preserves top-level element order', () => {
    const normalized = normalizePageSpec({
      title: 'Home',
      elements: {
        header: {
          type: 'js.header',
          props: {},
        },
        hero: {
          type: 'js.hero',
          props: {},
        },
        footer: {
          type: 'js.footer',
          props: {},
        },
      },
    });

    expect(normalized.title).toBe('Home');
    expect(normalized.spec.root).toBe('canvas:component-tree');
    expect(normalized.spec.elements['canvas:component-tree']).toEqual({
      type: 'canvas:component-tree',
      props: {},
      children: ['header', 'hero', 'footer'],
    });
  });

  it('only includes unreferenced elements as top-level page children', () => {
    const normalized = normalizePageSpec({
      title: 'Home',
      elements: {
        header: {
          type: 'js.header',
          slots: {
            branding: ['logo'],
          },
        },
        logo: {
          type: 'js.logo',
          props: {},
        },
        hero: {
          type: 'js.hero',
          props: {},
        },
      },
    });

    expect(normalized.spec.elements['canvas:component-tree']).toEqual({
      type: 'canvas:component-tree',
      props: {},
      children: ['header', 'hero'],
    });
  });

  it('parses valid page specs and accepts js-prefixed component names', () => {
    const result = parsePageSpec(
      {
        title: 'Home',
        elements: {
          hero: {
            type: 'js.hero',
            props: {
              title: 'Hello world',
            },
          },
        },
      },
      '/tmp/pages/home.json',
      {
        componentNames: ['hero'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.page?.spec.root).toBe('canvas:component-tree');
  });

  it('accepts an optional top-level $schema key', () => {
    const result = parsePageSpec(
      {
        $schema: 'https://example.com/page-spec.schema.json',
        title: 'Home',
        elements: {
          hero: {
            type: 'js.hero',
            props: {
              title: 'Hello world',
            },
          },
        },
      },
      '/tmp/pages/home.json',
      {
        componentNames: ['hero'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.page?.spec.root).toBe('canvas:component-tree');
  });

  it('accepts an optional top-level uuid key', () => {
    const result = parsePageSpec(
      {
        uuid: '123e4567-e89b-12d3-a456-426614174000',
        title: 'Home',
        elements: {
          hero: {
            type: 'js.hero',
            props: {
              title: 'Hello world',
            },
          },
        },
      },
      '/tmp/pages/home.json',
      {
        componentNames: ['hero'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.page?.spec.root).toBe('canvas:component-tree');
  });

  it('extracts page titles for discovery metadata', () => {
    const result = parsePageSpecMetadata(
      {
        title: 'Home',
        elements: {
          hero: {
            type: 'js.hero',
          },
        },
      },
      '/tmp/pages/home.json',
    );

    expect(result.issues).toEqual([]);
    expect(result.page).toEqual({
      title: 'Home',
    });
  });

  it('rejects authored root keys', () => {
    const result = parsePageSpec(
      {
        title: 'Home',
        root: 'home',
        elements: {
          hero: {
            type: 'hero',
          },
        },
      },
      '/tmp/pages/home.json',
    );

    expect(result.page).toBeNull();
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].message).toContain('unexpected top-level keys');
  });

  it('rejects empty top-level $schema values', () => {
    const result = parsePageSpec(
      {
        $schema: '',
        title: 'Home',
        elements: {
          hero: {
            type: 'hero',
          },
        },
      },
      '/tmp/pages/home.json',
    );

    expect(result.page).toBeNull();
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].path).toBe('/tmp/pages/home.json#$schema');
    expect(result.issues[0].message).toContain('non-empty $schema string');
  });

  it('rejects authored canvas:component-tree elements', () => {
    const result = parsePageSpec(
      {
        title: 'Home',
        elements: {
          'canvas:component-tree': {
            type: 'canvas:component-tree',
          },
        },
      },
      '/tmp/pages/home.json',
    );

    expect(result.page).toBeNull();
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].message).toContain(
      'canvas:component-tree directly',
    );
  });

  it('rejects missing slot references', () => {
    const result = parsePageSpec(
      {
        title: 'Home',
        elements: {
          card: {
            type: 'card',
            slots: {
              header: ['missing-header'],
            },
          },
        },
      },
      '/tmp/pages/home.json',
      {
        componentNames: ['card'],
      },
    );

    expect(result.page).toBeNull();
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].message).toContain(
      'Unknown element ID "missing-header" referenced from slot "header".',
    );
  });

  it('rejects unknown component types', () => {
    const result = parsePageSpec(
      {
        title: 'Home',
        elements: {
          hero: {
            type: 'unknown-component',
            props: {},
          },
        },
      },
      '/tmp/pages/home.json',
      {
        componentNames: ['hero'],
      },
    );

    expect(result.page).toBeNull();
    expect(result.issues).toHaveLength(1);
    expect(result.issues[0].message).toContain('unknown-component');
  });
});
