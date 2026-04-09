import { describe, expect, it } from 'vitest';

import { resolveWorkbenchPreviewNavigation } from './resolve-workbench-preview-navigation';

import type { PreviewManifestComponent } from './preview-contract';

const origin = 'http://localhost:5173';

function manifestComponent(
  id: string,
  mockCount: number,
): PreviewManifestComponent {
  const mocks = Array.from({ length: mockCount }, (_, index) => ({
    id: `m${index}`,
    label: `Mock ${index}`,
    sourcePath: '',
    spec: { root: 'r', elements: { r: { type: 'x', props: {} } } },
  }));
  return {
    id,
    name: id,
    label: id,
    relativeDirectory: '',
    projectRelativeDirectory: '',
    metadataPath: '',
    js: { entryPath: null, url: null },
    css: { entryPath: null, url: null },
    previewable: true,
    ineligibilityReason: null,
    exampleProps: {},
    mocks,
  };
}

const baseContext = {
  workbenchOrigin: origin,
  pageSlugs: new Set(['about', 'home']),
  manifestComponents: [
    manifestComponent('hero', 2),
    manifestComponent('footer', 0),
  ],
};

describe('resolveWorkbenchPreviewNavigation', () => {
  it('returns open for other origins', () => {
    const url = new URL('https://example.com/foo');
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'open',
      href: 'https://example.com/foo',
    });
  });

  it('returns open for mailto', () => {
    const url = new URL('mailto:a@b.com');
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'open',
      href: 'mailto:a@b.com',
    });
  });

  it('navigates /page when listing pages', () => {
    const url = new URL(`${origin}/page`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'navigate',
      path: '/page',
    });
  });

  it('navigates /page/:slug when slug exists', () => {
    const url = new URL(`${origin}/page/about`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'navigate',
      path: '/page/about',
    });
  });

  it('opens unknown page slug in new window', () => {
    const url = new URL(`${origin}/page/missing`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'open',
      href: `${origin}/page/missing`,
    });
  });

  it('navigates /component', () => {
    const url = new URL(`${origin}/component`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'navigate',
      path: '/component',
    });
  });

  it('navigates /component/:id when component exists', () => {
    const url = new URL(`${origin}/component/hero`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'navigate',
      path: '/component/hero',
    });
  });

  it('opens /component/:id when component missing', () => {
    const url = new URL(`${origin}/component/unknown`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'open',
      href: `${origin}/component/unknown`,
    });
  });

  it('navigates /component/:id/:mockIndex when in range', () => {
    const url = new URL(`${origin}/component/hero/1`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'navigate',
      path: '/component/hero/1',
    });
  });

  it('opens /component/:id/:mockIndex when mock out of range', () => {
    const url = new URL(`${origin}/component/hero/99`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'open',
      href: `${origin}/component/hero/99`,
    });
  });

  it('opens Vite internal paths', () => {
    const url = new URL(`${origin}/@fs/foo`);
    expect(resolveWorkbenchPreviewNavigation(url, baseContext)).toEqual({
      kind: 'open',
      href: `${origin}/@fs/foo`,
    });
  });
});
