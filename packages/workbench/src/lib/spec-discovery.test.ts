import { describe, expect, it } from 'vitest';

import {
  toDiscoveredPageName,
  toPreviewManifestComponentMocks,
  toPreviewPageSpec,
} from './spec-discovery';

describe('spec-discovery', () => {
  it('uses the authored page title when present', () => {
    expect(
      toDiscoveredPageName(
        {
          title: 'Home',
          elements: {},
        },
        '/tmp/pages/home.json',
        'home',
      ),
    ).toBe('Home');
  });

  it('falls back to the discovered page name when page metadata is invalid', () => {
    expect(
      toDiscoveredPageName({ elements: {} }, '/tmp/pages/home.json', 'home'),
    ).toBe('home');
  });

  it('builds preview manifest mocks from authored mock entries', () => {
    const result = toPreviewManifestComponentMocks(
      {
        mocks: [
          {
            name: 'Centered',
            props: {
              text: 'Hello world',
            },
          },
        ],
      },
      {
        sourcePath: '/tmp/components/heading/mocks.json',
        componentRoot: '/tmp',
        componentName: 'heading',
        componentNames: ['heading'],
        componentExampleProps: {
          textAlign: 'left',
        },
        componentRequiredPropNames: ['textAlign'],
      },
    );

    expect(result.warnings).toEqual([]);
    expect(result.mocks).toEqual([
      {
        id: 'components/heading/mocks.json#0',
        label: 'Centered',
        sourcePath: '/tmp/components/heading/mocks.json',
        spec: {
          root: 'canvas:mock-root',
          elements: {
            'canvas:mock-root': {
              type: 'heading',
              props: {
                textAlign: 'left',
                text: 'Hello world',
              },
            },
          },
        },
      },
    ]);
  });

  it('normalizes authored page specs for preview rendering', () => {
    const result = toPreviewPageSpec(
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
      {
        sourcePath: '/tmp/pages/home.json',
        componentNames: ['hero'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.spec).toEqual({
      root: 'canvas:component-tree',
      elements: {
        hero: {
          type: 'js.hero',
          props: {
            title: 'Hello world',
          },
        },
        'canvas:component-tree': {
          type: 'canvas:component-tree',
          props: {},
          children: ['hero'],
        },
      },
    });
  });

  it('keeps nested page elements out of the synthetic top-level children list', () => {
    const result = toPreviewPageSpec(
      {
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
      },
      {
        sourcePath: '/tmp/pages/home.json',
        componentNames: ['header', 'logo', 'hero'],
      },
    );

    expect(result.issues).toEqual([]);
    expect(result.spec?.elements['canvas:component-tree']).toEqual({
      type: 'canvas:component-tree',
      props: {},
      children: ['header', 'hero'],
    });
  });
});
