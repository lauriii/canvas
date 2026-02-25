import { describe, expect, it } from 'vitest';
import { toViteFsUrl } from '@drupal-canvas/vite-compat/runtime';

import {
  buildPreviewManifest,
  isPreviewFrameEvent,
  isPreviewRenderRequest,
  toPreviewManifestComponent,
} from './preview-contract';

describe('preview-contract', () => {
  it('creates Vite fs URLs from absolute paths', () => {
    expect(toViteFsUrl('/Users/example/component.tsx')).toBe(
      '/@fs/Users/example/component.tsx',
    );
    expect(toViteFsUrl('C:\\workspace\\component.tsx')).toBe(
      '/@fs/C:/workspace/component.tsx',
    );
  });

  it('marks components with supported JS entries as previewable', () => {
    const component = toPreviewManifestComponent({
      id: 'abc',
      kind: 'index',
      name: 'hero',
      relativeDirectory: 'src/hero',
      metadataPath: '/tmp/src/hero/component.yml',
      jsEntryPath: '/tmp/src/hero/index.tsx',
      cssEntryPath: '/tmp/src/hero/index.css',
    });

    expect(component.previewable).toBe(true);
    expect(component.ineligibilityReason).toBeNull();
    expect(component.moduleUrl).toBe('/@fs/tmp/src/hero/index.tsx');
    expect(component.cssUrl).toBe('/@fs/tmp/src/hero/index.css');
    expect(component.exampleProps).toEqual({});
  });

  it('marks components without JS entries as non-previewable', () => {
    const component = toPreviewManifestComponent({
      id: 'abc',
      kind: 'named',
      name: 'hero',
      relativeDirectory: 'src/hero',
      metadataPath: '/tmp/src/hero/hero.component.yml',
      jsEntryPath: null,
      cssEntryPath: null,
    });

    expect(component.previewable).toBe(false);
    expect(component.ineligibilityReason).toBe('missing_js_entry');
    expect(component.moduleUrl).toBeNull();
    expect(component.exampleProps).toEqual({});
  });

  it('marks unsupported JS extensions as non-previewable', () => {
    const component = toPreviewManifestComponent({
      id: 'abc',
      kind: 'named',
      name: 'hero',
      relativeDirectory: 'src/hero',
      metadataPath: '/tmp/src/hero/hero.component.yml',
      jsEntryPath: '/tmp/src/hero/hero.mjs',
      cssEntryPath: null,
    });

    expect(component.previewable).toBe(false);
    expect(component.ineligibilityReason).toBe('unsupported_js_extension');
  });

  it('builds a preview manifest from discovery result', () => {
    const manifest = buildPreviewManifest({
      scanRoot: '/tmp/workspace',
      components: [
        {
          id: 'one',
          kind: 'index',
          name: 'card',
          directory: '/tmp/workspace/src/card',
          relativeDirectory: 'src/card',
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

    expect(manifest.scanRoot).toBe('/tmp/workspace');
    expect(manifest.components).toHaveLength(1);
    expect(manifest.components[0].previewable).toBe(true);
    expect(manifest.components[0].exampleProps).toEqual({});
    expect(manifest.globalCssUrl).toBeNull();
    expect(manifest.warnings).toHaveLength(1);
  });

  it('validates parent-to-frame render messages', () => {
    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          mode: 'component',
          componentId: 'id-1',
          moduleUrl: '/@fs/tmp/file.tsx',
          cssUrl: '/@fs/tmp/file.css',
          globalCssUrl: '/@id/virtual:canvas-host-global.css',
          props: { title: 'Example' },
        },
      }),
    ).toBe(true);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          mode: 'component',
          componentId: 1,
          moduleUrl: '/@fs/tmp/file.tsx',
          cssUrl: null,
          globalCssUrl: null,
          props: {},
        },
      }),
    ).toBe(false);

    expect(
      isPreviewRenderRequest({
        source: 'canvas-workbench-parent',
        type: 'preview:render',
        payload: {
          mode: 'page',
          pageSlug: 'home',
          pageSpecUrl: '/@fs/tmp/pages/home.json',
          globalCssUrl: null,
          components: [
            {
              name: 'hero',
              jsEntryUrl: '/@fs/tmp/src/components/hero/index.tsx',
              cssEntryUrl: null,
            },
          ],
        },
      }),
    ).toBe(true);
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
          kind: 'component',
          renderId: 123,
        },
      }),
    ).toBe(false);
  });
});
