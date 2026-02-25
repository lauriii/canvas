import {
  isSupportedPreviewModulePath,
  toViteFsUrl,
} from '@drupal-canvas/vite-compat/runtime';

import type { DiscoveryResult, DiscoveryWarning } from './discovery-client';

export type PreviewIneligibilityReason =
  | 'missing_js_entry'
  | 'unsupported_js_extension';

export interface PreviewManifestComponent {
  id: string;
  kind: 'named' | 'index';
  name: string;
  relativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
  previewable: boolean;
  ineligibilityReason: PreviewIneligibilityReason | null;
  moduleUrl: string | null;
  cssUrl: string | null;
  exampleProps: Record<string, unknown>;
}

export interface PreviewManifest {
  scanRoot: string;
  components: PreviewManifestComponent[];
  warnings: DiscoveryWarning[];
  globalCssUrl: string | null;
}

export interface PreviewComponentRenderRequest {
  source: 'canvas-workbench-parent';
  type: 'preview:render';
  payload: {
    mode: 'component';
    componentId: string;
    moduleUrl: string;
    cssUrl: string | null;
    globalCssUrl: string | null;
    props: Record<string, unknown>;
  };
}

export interface PreviewPageRenderRequest {
  source: 'canvas-workbench-parent';
  type: 'preview:render';
  payload: {
    mode: 'page';
    pageSlug: string;
    pageSpecUrl: string;
    globalCssUrl: string | null;
    components: Array<{
      name: string;
      jsEntryUrl: string;
      cssEntryUrl: string | null;
    }>;
  };
}

export type PreviewRenderRequest =
  | PreviewComponentRenderRequest
  | PreviewPageRenderRequest;

export interface PreviewFrameReady {
  source: 'canvas-workbench-frame';
  type: 'preview:ready';
}

export interface PreviewFrameRendered {
  source: 'canvas-workbench-frame';
  type: 'preview:rendered';
  payload: {
    kind: 'component' | 'page';
    renderId: string;
  };
}

export interface PreviewFrameError {
  source: 'canvas-workbench-frame';
  type: 'preview:error';
  payload: {
    renderId: string | null;
    message: string;
  };
}

export type PreviewFrameEvent =
  | PreviewFrameReady
  | PreviewFrameRendered
  | PreviewFrameError;

export function toPreviewManifestComponent(component: {
  id: string;
  kind: 'named' | 'index';
  name: string;
  relativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
}): PreviewManifestComponent {
  if (!component.jsEntryPath) {
    return {
      ...component,
      previewable: false,
      ineligibilityReason: 'missing_js_entry',
      moduleUrl: null,
      cssUrl: null,
      exampleProps: {},
    };
  }

  if (!isSupportedPreviewModulePath(component.jsEntryPath)) {
    return {
      ...component,
      previewable: false,
      ineligibilityReason: 'unsupported_js_extension',
      moduleUrl: null,
      cssUrl: null,
      exampleProps: {},
    };
  }

  return {
    ...component,
    previewable: true,
    ineligibilityReason: null,
    moduleUrl: toViteFsUrl(component.jsEntryPath),
    cssUrl: component.cssEntryPath ? toViteFsUrl(component.cssEntryPath) : null,
    exampleProps: {},
  };
}

export function buildPreviewManifest(
  discoveryResult: DiscoveryResult,
): PreviewManifest {
  return {
    scanRoot: discoveryResult.scanRoot,
    components: discoveryResult.components.map(toPreviewManifestComponent),
    warnings: discoveryResult.warnings,
    globalCssUrl: null,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function isPreviewRenderRequest(
  value: unknown,
): value is PreviewRenderRequest {
  if (!isRecord(value)) {
    return false;
  }

  if (
    value.source !== 'canvas-workbench-parent' ||
    value.type !== 'preview:render'
  ) {
    return false;
  }

  if (!isRecord(value.payload)) {
    return false;
  }

  if (value.payload.mode === 'component') {
    const { componentId, moduleUrl, cssUrl, globalCssUrl, props } =
      value.payload;
    return (
      typeof componentId === 'string' &&
      typeof moduleUrl === 'string' &&
      (typeof cssUrl === 'string' || cssUrl === null) &&
      (typeof globalCssUrl === 'string' || globalCssUrl === null) &&
      typeof props === 'object' &&
      props !== null
    );
  }

  if (value.payload.mode === 'page') {
    const { pageSlug, pageSpecUrl, globalCssUrl, components } = value.payload;
    return (
      typeof pageSlug === 'string' &&
      typeof pageSpecUrl === 'string' &&
      (typeof globalCssUrl === 'string' || globalCssUrl === null) &&
      Array.isArray(components) &&
      components.every(
        (component) =>
          isRecord(component) &&
          typeof component.name === 'string' &&
          typeof component.jsEntryUrl === 'string' &&
          (typeof component.cssEntryUrl === 'string' ||
            component.cssEntryUrl === null),
      )
    );
  }

  return false;
}

export function isPreviewFrameEvent(
  value: unknown,
): value is PreviewFrameEvent {
  if (!isRecord(value) || value.source !== 'canvas-workbench-frame') {
    return false;
  }

  if (value.type === 'preview:ready') {
    return true;
  }

  if (value.type === 'preview:rendered') {
    return (
      isRecord(value.payload) &&
      (value.payload.kind === 'component' || value.payload.kind === 'page') &&
      typeof value.payload.renderId === 'string'
    );
  }

  if (value.type === 'preview:error') {
    return (
      isRecord(value.payload) &&
      (typeof value.payload.renderId === 'string' ||
        value.payload.renderId === null) &&
      typeof value.payload.message === 'string'
    );
  }

  return false;
}
