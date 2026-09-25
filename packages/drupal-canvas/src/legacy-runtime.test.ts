import { afterEach, describe, expect, it } from 'vitest';

import { getPageData, getSiteData } from './drupal-utils';
import { JsonApiClient } from './jsonapi-client';
import { AGENT_MIGRATION_PROMPT } from './migration';
import {
  CANVAS_RUNTIME_GLOBAL,
  declareCanvasRuntime,
  getCanvasRuntime,
} from './runtime';

import type { DrupalSettings } from '@drupal-canvas/types';

type TestGlobal = typeof globalThis & {
  [CANVAS_RUNTIME_GLOBAL]?: unknown;
};

const testGlobal = globalThis as TestGlobal;

function setDrupalSettings(v0: Record<string, unknown> | undefined): void {
  if (v0 === undefined) {
    delete (globalThis as { drupalSettings?: unknown }).drupalSettings;
    return;
  }
  globalThis.drupalSettings = {
    canvasData: { v0 },
  } as unknown as DrupalSettings;
}

describe('legacy runtime guards', () => {
  afterEach(() => {
    delete testGlobal[CANVAS_RUNTIME_GLOBAL];
    setDrupalSettings(undefined);
  });

  it('reports no runtime by default and ignores malformed markers', () => {
    expect(getCanvasRuntime()).toBeNull();
    testGlobal[CANVAS_RUNTIME_GLOBAL] = { environment: 'browser' };
    expect(getCanvasRuntime()).toBeNull();
    testGlobal[CANVAS_RUNTIME_GLOBAL] = 'drupal';
    expect(getCanvasRuntime()).toBeNull();
  });

  it('throws actionable errors outside Drupal and Workbench', () => {
    setDrupalSettings({ baseUrl: 'https://drupal.example', pageTitle: 'x' });
    expect(() => getPageData()).toThrowError(/getPageData\(\)/);
    expect(() => getPageData()).toThrowError(/usePageContext\(\)/);
    expect(() => getPageData()).toThrowError(/page\.context\.page/);
    expect(() => getSiteData()).toThrowError(/useSiteContext\(\)/);
    expect(() => getSiteData()).toThrowError(/page\.context\.site/);
    expect(() => new JsonApiClient()).toThrowError(/new JsonApiClient\(\)/);
    expect(() => new JsonApiClient('https://drupal.example')).toThrowError(
      /useJsonApiClient\(\)/,
    );
    expect(() => new JsonApiClient()).toThrowError(/getClient\(\)/);
    let message = '';
    try {
      getPageData();
    } catch (error) {
      message = (error as Error).message;
    }
    expect(message).toContain(AGENT_MIGRATION_PROMPT);
  });

  it.each(['drupal', 'workbench'] as const)(
    'keeps the legacy APIs working in %s',
    (environment) => {
      declareCanvasRuntime(environment);
      setDrupalSettings({
        baseUrl: 'https://drupal.example',
        pageTitle: 'Title',
        breadcrumbs: [],
        branding: { homeUrl: '/', siteName: 'Site', siteSlogan: '' },
        jsonapiSettings: { apiPrefix: 'api' },
      });
      expect(getPageData()).toEqual({
        pageTitle: 'Title',
        breadcrumbs: [],
        mainEntity: null,
      });
      expect(getSiteData().branding.siteName).toBe('Site');
      const client = new JsonApiClient();
      expect(client.baseUrl).toBe('https://drupal.example');
      expect(client.apiPrefix).toBe('api');
      expect(client.serializer).toBeDefined();
      expect(client.resourceVersion).toBeNull();
    },
  );

  it('lets legacy callers disable the serializer explicitly', () => {
    declareCanvasRuntime('workbench');
    setDrupalSettings({ baseUrl: 'https://drupal.example' });
    const client = new JsonApiClient(undefined, { serializer: undefined });
    expect(client.serializer).toBeUndefined();
    expect(client.apiPrefix).toBe('jsonapi');
  });

  it('rejects a site without JSON:API', () => {
    declareCanvasRuntime('drupal');
    setDrupalSettings({
      baseUrl: 'https://drupal.example',
      jsonapiSettings: null,
    });
    expect(() => new JsonApiClient()).toThrowError(/JSON:API module/);
  });
});
