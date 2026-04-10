import { describe, expect, it } from 'vitest';

import {
  buildPreviewManifest,
  isPreviewFrameEvent,
  isPreviewRenderRequest,
  isWorkbenchDiscoveryRefreshMessage,
  toPreviewManifestComponent,
} from './preview-contract';

describe('preview-contract', () => {
  it('marks components with supported JS entries as previewable', () => {
    const component = toPreviewManifestComponent({
      id: 'abc',
      name: 'hero',
      relativeDirectory: 'src/hero',
      projectRelativeDirectory: 'packages/site/src/hero',
      metadataPath: '/tmp/src/hero/component.yml',
      jsEntryPath: '/tmp/src/hero/index.tsx',
      cssEntryPath: '/tmp/src/hero/index.css',
    });

    expect(component.previewable).toBe(true);
    expect(component.ineligibilityReason).toBeNull();
    expect(component.js.entryPath).toBe('/tmp/src/hero/index.tsx');
    expect(component.js.url).toBe('/@fs/tmp/src/hero/index.tsx');
    expect(component.css.entryPath).toBe('/tmp/src/hero/index.css');
    expect(component.css.url).toBe('/@fs/tmp/src/hero/index.css');
    expect(component.exampleProps).toEqual({});
    expect(component.mocks).toEqual([]);
  });

  it('marks components without JS entries as non-previewable', () => {
    const component = toPreviewManifestComponent({
      id: 'abc',
      name: 'hero',
      relativeDirectory: 'src/hero',
      projectRelativeDirectory: 'packages/site/src/hero',
      metadataPath: '/tmp/src/hero/hero.component.yml',
      jsEntryPath: null,
      cssEntryPath: null,
    });

    expect(component.previewable).toBe(false);
    expect(component.ineligibilityReason).toBe('missing_js_entry');
    expect(component.js.entryPath).toBeNull();
    expect(component.js.url).toBeNull();
    expect(component.css.entryPath).toBeNull();
    expect(component.css.url).toBeNull();
    expect(component.exampleProps).toEqual({});
    expect(component.mocks).toEqual([]);
  });

  it('marks unsupported JS extensions as non-previewable', () => {
    const component = toPreviewManifestComponent({
      id: 'abc',
      name: 'hero',
      relativeDirectory: 'src/hero',
      projectRelativeDirectory: 'packages/site/src/hero',
      metadataPath: '/tmp/src/hero/hero.component.yml',
      jsEntryPath: '/tmp/src/hero/hero.mjs',
      cssEntryPath: null,
    });

    expect(component.previewable).toBe(false);
    expect(component.ineligibilityReason).toBe('unsupported_js_extension');
  });

  it('builds a preview manifest from discovery result', () => {
    const manifest = buildPreviewManifest({
      componentRoot: '/tmp/workspace',
      projectRoot: '/tmp',
      components: [
        {
          id: 'one',
          kind: 'index',
          name: 'card',
          directory: '/tmp/workspace/src/card',
          relativeDirectory: 'src/card',
          projectRelativeDirectory: 'workspace/src/card',
          metadataPath: '/tmp/workspace/src/card/component.yml',
          jsEntryPath: '/tmp/workspace/src/card/index.tsx',
          cssEntryPath: '/tmp/workspace/src/card/index.css',
        },
      ],
      pages: [],
      warnings: [
        {
          code: 'duplicate_definition',
          message: 'duplicate',
          path: '/tmp/workspace/src/card/component.yml',
        },
      ],
      stats: {
        scannedFiles: 1,
        ignoredFiles: 0,
      },
    });

    expect(manifest.componentRoot).toBe('/tmp/workspace');
    expect(manifest.components).toHaveLength(1);
    expect(manifest.components[0].previewable).toBe(true);
    expect(manifest.components[0].exampleProps).toEqual({});
    expect(manifest.components[0].mocks).toEqual([]);
    expect(manifest.globalCssUrl).toBeNull();
    expect(manifest.warnings).toHaveLength(1);
  });

  it('validates parent-to-frame render messages', () => {
    const validSpec = {
      root: 'root',
      elements: {
        root: {
          type: 'js.hero',
          props: {},
        },
      },
    };

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'id-1',
          renderType: 'component',
          spec: validSpec,
          registrySources: [
            {
              name: 'hero',
              jsEntryUrl: '/@fs/tmp/file.tsx',
            },
          ],
          cssUrls: ['/@id/virtual:canvas-host-global.css', '/@fs/tmp/file.css'],
          shellPath: '/component/hero',
        },
      }),
    ).toBe(true);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'id-1',
          renderType: 'component',
          spec: validSpec,
          registrySources: [
            {
              name: 'hero',
              jsEntryUrl: '/@fs/tmp/file.tsx',
            },
          ],
          cssUrls: [],
          shellPath: '',
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 1,
          renderType: 'component',
          spec: validSpec,
          registrySources: [],
          cssUrls: [],
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'home',
          renderType: 'component',
          spec: {
            root: 123,
            elements: {},
          },
          registrySources: [],
          cssUrls: [],
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'home',
          renderType: 'component',
          spec: validSpec,
          registrySources: [
            {
              name: 'hero',
              jsEntryUrl: 123,
            },
          ],
          cssUrls: [],
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'home',
          renderType: 'component',
          spec: validSpec,
          registrySources: [],
          cssUrls: ['/@fs/tmp/file.css', 123],
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'home',
          renderType: 'component',
          spec: validSpec,
          registrySources: [],
          cssUrls: false,
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          renderId: 'home',
          renderType: 'unknown',
          spec: validSpec,
          registrySources: [],
          cssUrls: [],
        },
      }),
    ).toBe(false);
  });

  it('validates frame-to-parent event payloads', () => {
    expect(
      isPreviewFrameEvent({
        source: 'canvas-workbench-frame',
        type: 'preview:ready',
      }),
    ).toBe(true);

    expect(
      isPreviewFrameEvent({
        source: 'canvas-workbench-frame',
        type: 'preview:error',
        payload: {
          renderId: null,
          message: 'failed',
        },
      }),
    ).toBe(true);

    expect(
      isPreviewFrameEvent({
        source: 'canvas-workbench-frame',
        type: 'preview:rendered',
        payload: {
          type: 'component',
          renderId: 123,
        },
      }),
    ).toBe(false);

    expect(
      isPreviewFrameEvent({
        source: 'canvas-workbench-frame',
        type: 'preview:shell-sync',
        payload: {
          path: '/page/about',
        },
      }),
    ).toBe(true);

    expect(
      isPreviewFrameEvent({
        source: 'canvas-workbench-frame',
        type: 'preview:shell-sync',
        payload: {
          path: 1,
        },
      }),
    ).toBe(false);
  });

  describe('isWorkbenchDiscoveryRefreshMessage', () => {
    it('accepts the workbench discovery refresh shape', () => {
      expect(
        isWorkbenchDiscoveryRefreshMessage({
          source: 'canvas-workbench-parent',
          type: 'workbench:discovery-refresh',
        }),
      ).toBe(true);
    });

    it('rejects other messages', () => {
      expect(isWorkbenchDiscoveryRefreshMessage(null)).toBe(false);
      expect(
        isWorkbenchDiscoveryRefreshMessage({
          source: 'canvas-workbench-parent',
          type: 'preview:render',
        }),
      ).toBe(false);
    });
  });
});
