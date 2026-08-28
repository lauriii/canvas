import { describe, expect, it } from 'vitest';

import {
  computeWorkbenchStructuralFingerprint,
  shouldHideComponentPreviewFrame,
  shouldSkipWorkbenchIframeRemount,
} from './workbench-preview-iframe-remount';

import type { DiscoveryResult } from './discovery-client';
import type { PreviewManifest } from './preview-contract';

const baseDiscovery: DiscoveryResult = {
  componentRoot: 'cr',
  projectRoot: 'pr',
  components: [
    {
      id: 'hero',
      kind: 'index',
      name: 'Hero',
      directory: 'd',
      relativeDirectory: 'd',
      projectRelativeDirectory: 'd',
      metadataPath: 'component.yml',
      jsEntryPath: 'Hero.tsx',
      cssEntryPath: null,
    },
  ],
  pages: [
    {
      slug: 'home',
      name: 'Home',
      uuid: null,
      path: '',
      relativePath: '',
    },
  ],
  contentTemplates: [],
  pageTemplates: [],
  warnings: [],
  stats: { scannedFiles: 0, ignoredFiles: 0 },
};

const baseManifest: PreviewManifest = {
  componentRoot: '',
  components: [],
  warnings: [],
  globalCssUrl: null,
};

describe('computeWorkbenchStructuralFingerprint', () => {
  it('is stable for same structure', () => {
    const a = computeWorkbenchStructuralFingerprint(
      baseDiscovery,
      baseManifest,
    );
    const b = computeWorkbenchStructuralFingerprint(
      structuredClone(baseDiscovery),
      structuredClone(baseManifest),
    );
    expect(a).toBe(b);
  });
});

describe('shouldHideComponentPreviewFrame', () => {
  it('hides a previous component until the selected component settles', () => {
    const component = { previewable: true, metadataErrors: [] };

    expect(
      shouldHideComponentPreviewFrame(component, 'component-b', 'component-a'),
    ).toBe(true);
    expect(
      shouldHideComponentPreviewFrame(component, 'component-b', 'component-b'),
    ).toBe(false);
  });

  it('hides the frame behind component metadata errors', () => {
    expect(
      shouldHideComponentPreviewFrame(
        {
          previewable: false,
          metadataErrors: [
            {
              sourcePath: 'component.yml',
              path: '$.type',
              message: 'invalid',
            },
          ],
        },
        null,
        null,
      ),
    ).toBe(true);
  });
});

describe('shouldSkipWorkbenchIframeRemount', () => {
  const fp = computeWorkbenchStructuralFingerprint(baseDiscovery, baseManifest);

  it('returns false when reloadFrameOnly is not false', () => {
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: { reloadFrameOnly: true },
        previousFingerprint: fp,
        nextFingerprint: fp,
      }),
    ).toBe(false);
  });

  it('returns false when event is not change', () => {
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: {
          reloadFrameOnly: false,
          filePath: 'pages/home.json',
          event: 'add',
        },
        previousFingerprint: fp,
        nextFingerprint: fp,
      }),
    ).toBe(false);
  });

  it('returns false when filePath is missing', () => {
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: { reloadFrameOnly: false, event: 'change' },
        previousFingerprint: fp,
        nextFingerprint: fp,
      }),
    ).toBe(false);
  });

  it('returns false when path does not support an in-place refresh', () => {
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: {
          reloadFrameOnly: false,
          filePath: 'pages/nested/foo.json',
          event: 'change',
        },
        previousFingerprint: fp,
        nextFingerprint: fp,
      }),
    ).toBe(false);
  });

  it('returns false when previous fingerprint is null', () => {
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: {
          reloadFrameOnly: false,
          filePath: 'pages/home.json',
          event: 'change',
        },
        previousFingerprint: null,
        nextFingerprint: fp,
      }),
    ).toBe(false);
  });

  it('returns false when structure changed', () => {
    const nextDiscovery = {
      ...baseDiscovery,
      pages: [
        ...baseDiscovery.pages,
        {
          slug: 'about',
          name: 'About',
          uuid: null,
          path: '',
          relativePath: '',
        },
      ],
    };
    const nextFp = computeWorkbenchStructuralFingerprint(
      nextDiscovery,
      baseManifest,
    );
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: {
          reloadFrameOnly: false,
          filePath: 'pages/home.json',
          event: 'change',
        },
        previousFingerprint: fp,
        nextFingerprint: nextFp,
      }),
    ).toBe(false);
  });

  it.each([
    'src/components/hero/component.yml',
    'src/components/hero/hero.component.yml',
  ])(
    'returns true for component metadata change at %s with same fingerprint',
    (filePath) => {
      expect(
        shouldSkipWorkbenchIframeRemount({
          payload: {
            reloadFrameOnly: false,
            filePath,
            event: 'change',
          },
          previousFingerprint: fp,
          nextFingerprint: fp,
        }),
      ).toBe(true);
    },
  );

  it('returns true for page json change with same fingerprint', () => {
    expect(
      shouldSkipWorkbenchIframeRemount({
        payload: {
          reloadFrameOnly: false,
          filePath: 'pages/home.json',
          event: 'change',
        },
        previousFingerprint: fp,
        nextFingerprint: fp,
      }),
    ).toBe(true);
  });
});
