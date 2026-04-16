import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  collectPageResults,
  preparePages,
  pushPages,
} from './prepare-pages-push';

import type { DiscoveredPage, DiscoveryResult } from '@drupal-canvas/discovery';
import type { ApiService } from '../services/api';
import type { PageListItem } from '../types/Page';

const emptyDiscoveryResult: DiscoveryResult = {
  componentRoot: '',
  projectRoot: '',
  components: [],
  pages: [],
  warnings: [],
  stats: { scannedFiles: 0, ignoredFiles: 0 },
};

function mockDiscoveredPage(
  name: string,
  uuid: string | null,
  filePath: string,
): DiscoveredPage {
  return {
    name,
    slug: name,
    uuid,
    path: filePath,
    relativePath: name + '.json',
  };
}

function mockPageListItem(
  id: number,
  uuid: string,
  title: string,
  pagePath: string,
): PageListItem {
  return {
    id,
    uuid,
    title,
    status: true,
    path: pagePath,
    internalPath: `/page/${id}`,
    autoSaveLabel: null,
    autoSavePath: null,
    links: {},
  };
}

describe('preparePages', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'prepare-pages-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('should prepare pages from local files', async () => {
    const filePath = path.join(tmpDir, 'home.json');
    await fs.writeFile(
      filePath,
      JSON.stringify({
        uuid: 'page-uuid-1',
        title: 'Home',
        elements: {
          'comp-1': { type: 'js.hero', props: { heading: 'Welcome' } },
        },
      }),
      'utf-8',
    );

    const pages = [mockDiscoveredPage('home', 'page-uuid-1', filePath)];
    const versions = new Map([['js.hero', 'v1']]);

    const { valid, failed } = await preparePages(
      pages,
      versions,
      emptyDiscoveryResult,
    );

    expect(failed).toHaveLength(0);
    expect(valid).toHaveLength(1);
    expect(valid[0].result.title).toBe('Home');
    expect(valid[0].result.uuid).toBe('page-uuid-1');
    expect(valid[0].result.components).toHaveLength(1);
    expect(valid[0].result.components[0].component_id).toBe('js.hero');
    expect(valid[0].result.components[0].component_version).toBe('v1');
  });

  it('should handle pages with no uuid', async () => {
    const filePath = path.join(tmpDir, 'new-page.json');
    await fs.writeFile(
      filePath,
      JSON.stringify({ title: 'New Page', elements: {} }),
      'utf-8',
    );

    const pages = [mockDiscoveredPage('new-page', null, filePath)];
    const { valid, failed } = await preparePages(
      pages,
      new Map(),
      emptyDiscoveryResult,
    );

    expect(failed).toHaveLength(0);
    expect(valid).toHaveLength(1);
    expect(valid[0].result.uuid).toBeNull();
  });

  it('should report failed pages for invalid JSON', async () => {
    const filePath = path.join(tmpDir, 'bad.json');
    await fs.writeFile(filePath, 'not valid json', 'utf-8');

    const pages = [mockDiscoveredPage('bad', null, filePath)];
    const { valid, failed } = await preparePages(
      pages,
      new Map(),
      emptyDiscoveryResult,
    );

    expect(valid).toHaveLength(0);
    expect(failed).toHaveLength(1);
  });
});

describe('pushPages', () => {
  let tmpDir: string;

  beforeEach(async () => {
    tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'push-pages-test-'));
  });

  afterEach(async () => {
    await fs.rm(tmpDir, { recursive: true, force: true });
  });

  it('should update existing pages', async () => {
    const filePath = path.join(tmpDir, 'home.json');
    await fs.writeFile(
      filePath,
      JSON.stringify({ uuid: 'page-uuid-1', title: 'Home', elements: {} }),
      'utf-8',
    );

    const prepared = [
      {
        index: 0,
        result: {
          uuid: 'page-uuid-1',
          title: 'Home',
          components: [],
          filePath,
          pendingMediaReconciliations: [],
        },
      },
    ];

    const remoteByUuid = new Map([
      ['page-uuid-1', mockPageListItem(1, 'page-uuid-1', 'Home', '/home')],
    ]);

    const api = {
      updatePage: vi.fn().mockResolvedValue({}),
      createPage: vi.fn(),
    } as unknown as Pick<ApiService, 'createPage' | 'updatePage'>;

    const results = await pushPages(prepared, remoteByUuid, api);

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(true);
    expect(results[0].result?.operation).toBe('Updated');
    expect(api.updatePage).toHaveBeenCalledTimes(1);
    expect(api.createPage).not.toHaveBeenCalled();
  });

  it('should create new pages and write UUID back', async () => {
    const filePath = path.join(tmpDir, 'new.json');
    await fs.writeFile(
      filePath,
      JSON.stringify({ title: 'New Page', elements: {} }),
      'utf-8',
    );

    const prepared = [
      {
        index: 0,
        result: {
          uuid: null as string | null,
          title: 'New Page',
          components: [],
          filePath,
          pendingMediaReconciliations: [],
        },
      },
    ];

    const api = {
      updatePage: vi.fn(),
      createPage: vi.fn().mockResolvedValue({
        uuid: 'server-assigned-uuid',
        id: 1,
        title: 'New Page',
        components: [],
      }),
    } as unknown as Pick<ApiService, 'createPage' | 'updatePage'>;

    const results = await pushPages(prepared, new Map(), api);

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(true);
    expect(results[0].result?.operation).toBe('Created');
    expect(api.createPage).toHaveBeenCalledTimes(1);
    expect(api.updatePage).not.toHaveBeenCalled();

    // Verify UUID was written back to the file.
    const content = JSON.parse(await fs.readFile(filePath, 'utf-8'));
    expect(content.uuid).toBe('server-assigned-uuid');
  });
});

describe('collectPageResults', () => {
  it('should collect successful results', () => {
    const pushResults = [
      {
        success: true,
        result: { title: 'Home', operation: 'Updated' as const },
        index: 0,
      },
      {
        success: true,
        result: { title: 'About', operation: 'Created' as const },
        index: 1,
      },
    ];

    const results = collectPageResults(pushResults, [], []);

    expect(results).toHaveLength(2);
    expect(results[0]).toEqual({
      itemName: 'Home',
      success: true,
      details: [{ content: 'Updated' }],
    });
    expect(results[1]).toEqual({
      itemName: 'About',
      success: true,
      details: [{ content: 'Created' }],
    });
  });

  it('should collect failed push results', () => {
    const pages = [mockDiscoveredPage('home', null, '/tmp/home.json')];

    const pushResults = [
      { success: false, error: new Error('API error'), index: 0 },
    ];

    const results = collectPageResults(pushResults, [], pages);

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(false);
    expect(results[0].itemName).toBe('home');
    expect(results[0].details?.[0].content).toBe('API error');
  });

  it('should collect failed preparations', () => {
    const pages = [mockDiscoveredPage('bad', null, '/tmp/bad.json')];

    const failedPreps = [{ index: 0, error: new Error('Invalid JSON') }];

    const results = collectPageResults([], failedPreps, pages);

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(false);
    expect(results[0].itemName).toBe('bad');
    expect(results[0].details?.[0].content).toBe('Invalid JSON');
  });
});
