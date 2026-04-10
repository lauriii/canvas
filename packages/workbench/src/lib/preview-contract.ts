import { isSupportedPreviewModulePath, toViteFsUrl } from './preview-runtime';

import type { Spec } from '@json-render/core';
import type { DiscoveryResult, DiscoveryWarning } from './discovery-client';

export type PreviewIneligibilityReason =
  | 'missing_js_entry'
  | 'unsupported_js_extension';

export interface PreviewManifestComponent {
  id: string;
  name: string;
  label: string;
  relativeDirectory: string;
  projectRelativeDirectory: string;
  metadataPath: string;
  js: {
    entryPath: string | null;
    url: string | null;
  };
  css: {
    entryPath: string | null;
    url: string | null;
  };
  previewable: boolean;
  ineligibilityReason: PreviewIneligibilityReason | null;
  exampleProps: Record<string, unknown>;
  mocks: PreviewManifestComponentMock[];
}

export interface PreviewManifestComponentMock {
  id: string;
  label: string;
  sourcePath: string;
  spec: Spec;
}

export interface PreviewWarning {
  code:
    | DiscoveryWarning['code']
    | 'invalid_mock_json'
    | 'invalid_mock_spec_file'
    | 'invalid_mock_spec_entry';
  message: string;
  path?: string;
}

export interface PreviewManifest {
  componentRoot: string;
  components: PreviewManifestComponent[];
  warnings: PreviewWarning[];
  globalCssUrl: string | null;
}

/** Parent tells the preview iframe to refetch discovery/manifest without remounting (e.g. after page JSON save). */
export interface WorkbenchDiscoveryRefresh {
  source: 'canvas-workbench-parent';
  type: 'workbench:discovery-refresh';
}

export interface PreviewRenderRequest {
  source: 'canvas-workbench-parent';
  type: 'preview:render';
  payload: {
    renderId: string;
    renderType: 'component' | 'page';
    spec: Spec;
    registrySources: Array<{
      name: string;
      jsEntryUrl: string;
    }>;
    cssUrls: string[];
    /** Workbench shell path (pathname + search) so the preview iframe MemoryRouter stays aligned. */
    shellPath?: string;
  };
}

export interface PreviewFrameReady {
  source: 'canvas-workbench-frame';
  type: 'preview:ready';
}

export interface PreviewFrameRendered {
  source: 'canvas-workbench-frame';
  type: 'preview:rendered';
  payload: {
    type: 'component' | 'page';
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

/** Iframe navigated internally; parent shell should match this path (SPA). */
export interface PreviewShellSync {
  source: 'canvas-workbench-frame';
  type: 'preview:shell-sync';
  payload: {
    path: string;
  };
}

export type PreviewFrameEvent =
  | PreviewFrameReady
  | PreviewFrameRendered
  | PreviewFrameError
  | PreviewShellSync;

export function toPreviewManifestComponent(component: {
  id: string;
  name: string;
  relativeDirectory: string;
  projectRelativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
}): PreviewManifestComponent {
  if (!component.jsEntryPath) {
    return {
      id: component.id,
      name: component.name,
      label: component.name,
      relativeDirectory: component.relativeDirectory,
      projectRelativeDirectory: component.projectRelativeDirectory,
      metadataPath: component.metadataPath,
      js: {
        entryPath: component.jsEntryPath,
        url: null,
      },
      css: {
        entryPath: component.cssEntryPath,
        url: null,
      },
      previewable: false,
      ineligibilityReason: 'missing_js_entry',
      exampleProps: {},
      mocks: [],
    };
  }

  if (!isSupportedPreviewModulePath(component.jsEntryPath)) {
    return {
      id: component.id,
      name: component.name,
      label: component.name,
      relativeDirectory: component.relativeDirectory,
      projectRelativeDirectory: component.projectRelativeDirectory,
      metadataPath: component.metadataPath,
      js: {
        entryPath: component.jsEntryPath,
        url: null,
      },
      css: {
        entryPath: component.cssEntryPath,
        url: null,
      },
      previewable: false,
      ineligibilityReason: 'unsupported_js_extension',
      exampleProps: {},
      mocks: [],
    };
  }

  return {
    id: component.id,
    name: component.name,
    label: component.name,
    relativeDirectory: component.relativeDirectory,
    projectRelativeDirectory: component.projectRelativeDirectory,
    metadataPath: component.metadataPath,
    js: {
      entryPath: component.jsEntryPath,
      url: toViteFsUrl(component.jsEntryPath),
    },
    css: {
      entryPath: component.cssEntryPath,
      url: component.cssEntryPath ? toViteFsUrl(component.cssEntryPath) : null,
    },
    previewable: true,
    ineligibilityReason: null,
    exampleProps: {},
    mocks: [],
  };
}

export function buildPreviewManifest(
  discoveryResult: DiscoveryResult,
): PreviewManifest {
  return {
    componentRoot: discoveryResult.componentRoot,
    components: discoveryResult.components.map(toPreviewManifestComponent),
    warnings: discoveryResult.warnings,
    globalCssUrl: null,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function isWorkbenchDiscoveryRefreshMessage(
  value: unknown,
): value is WorkbenchDiscoveryRefresh {
  return (
    isRecord(value) &&
    value.source === 'canvas-workbench-parent' &&
    value.type === 'workbench:discovery-refresh'
  );
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

  const { renderId, renderType, spec, registrySources, cssUrls, shellPath } =
    value.payload;
  if (
    shellPath !== undefined &&
    (typeof shellPath !== 'string' || shellPath.length === 0)
  ) {
    return false;
  }
  return (
    typeof renderId === 'string' &&
    (renderType === 'component' || renderType === 'page') &&
    isRecord(spec) &&
    typeof spec.root === 'string' &&
    isRecord(spec.elements) &&
    Array.isArray(registrySources) &&
    registrySources.every(
      (source) =>
        isRecord(source) &&
        typeof source.name === 'string' &&
        typeof source.jsEntryUrl === 'string',
    ) &&
    Array.isArray(cssUrls) &&
    cssUrls.every((url) => typeof url === 'string')
  );
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
      (value.payload.type === 'component' || value.payload.type === 'page') &&
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

  if (value.type === 'preview:shell-sync') {
    return isRecord(value.payload) && typeof value.payload.path === 'string';
  }

  return false;
}
