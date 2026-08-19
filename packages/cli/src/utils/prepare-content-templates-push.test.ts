import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it, vi } from 'vitest';

import {
  collectContentTemplateResults,
  prepareContentTemplates,
  pushContentTemplates,
} from './prepare-content-templates-push';

import type { DiscoveredContentTemplate } from '@drupal-canvas/discovery';
import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

vi.mock('@drupal-canvas/discovery', () => ({
  loadComponentsMetadata: vi.fn(async () => []),
}));

function mockDiscoveredContentTemplate(
  overrides: Partial<DiscoveredContentTemplate> = {},
): DiscoveredContentTemplate {
  return {
    name: 'Article full',
    slug: 'node.article.full',
    label: 'Article full',
    entityTypeId: 'node',
    bundle: 'article',
    viewMode: 'full',
    path: '/tmp/content-templates/node.article.full.json',
    relativePath: 'content-templates/node.article.full.json',
    ...overrides,
  };
}

describe('pushContentTemplates', () => {
  it('omits the authored label from the create request and retains it in the result', async () => {
    const createContentTemplate = vi.fn().mockResolvedValue({});

    const results = await pushContentTemplates(
      [
        {
          index: 0,
          result: {
            id: 'node.article.full',
            label: 'Article — Full content',
            entityTypeId: 'node',
            bundle: 'article',
            viewMode: 'full',
            pageVariant: null,
            components: [] satisfies CanvasComponentTree,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map(),
      {
        createContentTemplate,
        updateContentTemplate: vi.fn(),
      },
    );

    expect(createContentTemplate).toHaveBeenCalledExactlyOnceWith({
      entityType: 'node',
      bundle: 'article',
      viewMode: 'full',
      pageVariant: null,
      status: true,
      component_tree: [],
    });
    expect(results).toEqual([
      {
        success: true,
        result: {
          label: 'Article — Full content',
          id: 'node.article.full',
          operation: 'Created',
        },
        index: 0,
      },
    ]);
  });

  it('maps push result indices back to discovered content templates', async () => {
    const createContentTemplate = vi
      .fn()
      .mockRejectedValue(new Error('API error'));

    const results = await pushContentTemplates(
      [
        {
          index: 3,
          result: {
            id: 'node.article.full',
            label: 'Article full',
            entityTypeId: 'node',
            bundle: 'article',
            viewMode: 'full',
            pageVariant: null,
            components: [] satisfies CanvasComponentTree,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map(),
      {
        createContentTemplate,
        updateContentTemplate: vi.fn(),
      },
    );

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(false);
    expect(results[0].index).toBe(3);
  });

  it('includes an authored page variant selection in create and update payloads', async () => {
    const createContentTemplate = vi.fn().mockResolvedValue({});
    const updateContentTemplate = vi.fn().mockResolvedValue({});
    const prepared = {
      id: 'node.article.full',
      label: 'Article full',
      entityTypeId: 'node',
      bundle: 'article',
      viewMode: 'full',
      pageVariant: 'marketing',
      components: [] satisfies CanvasComponentTree,
      filePath: '/tmp/content-templates/node.article.full.json',
    };

    await pushContentTemplates([{ index: 0, result: prepared }], new Map(), {
      createContentTemplate,
      updateContentTemplate,
    });
    expect(createContentTemplate).toHaveBeenCalledWith(
      expect.objectContaining({ pageVariant: 'marketing' }),
    );

    await pushContentTemplates(
      [{ index: 0, result: prepared }],
      new Map([
        [
          'node.article.full',
          {
            id: 'node.article.full',
            label: 'Article full',
            status: true,
            entityType: 'node',
            bundle: 'article',
            viewMode: 'full',
          },
        ],
      ]),
      { createContentTemplate, updateContentTemplate },
    );
    expect(updateContentTemplate).toHaveBeenCalledWith(
      'node.article.full',
      expect.objectContaining({ pageVariant: 'marketing' }),
    );
  });

  it('clears an existing page variant selection when the authored file has none', async () => {
    const updateContentTemplate = vi.fn().mockResolvedValue({});

    await pushContentTemplates(
      [
        {
          index: 0,
          result: {
            id: 'node.article.full',
            label: 'Article full',
            entityTypeId: 'node',
            bundle: 'article',
            viewMode: 'full',
            pageVariant: null,
            components: [] satisfies CanvasComponentTree,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map([
        [
          'node.article.full',
          {
            id: 'node.article.full',
            label: 'Article full',
            status: true,
            entityType: 'node',
            bundle: 'article',
            viewMode: 'full',
            pageVariant: 'marketing',
          },
        ],
      ]),
      { createContentTemplate: vi.fn(), updateContentTemplate },
    );

    expect(updateContentTemplate).toHaveBeenCalledWith(
      'node.article.full',
      expect.objectContaining({ pageVariant: null }),
    );
  });
});

describe('prepareContentTemplates', () => {
  it('prepares an unset page variant selection as null', async () => {
    const temporaryDirectory = await fs.mkdtemp(
      path.join(os.tmpdir(), 'prepare-content-template-'),
    );
    const templatePath = path.join(
      temporaryDirectory,
      'node.article.full.json',
    );

    try {
      await fs.writeFile(
        templatePath,
        JSON.stringify({
          label: 'Article full',
          entityType: 'node',
          bundle: 'article',
          viewMode: 'full',
          elements: {},
        }),
        'utf-8',
      );

      const result = await prepareContentTemplates(
        [mockDiscoveredContentTemplate({ path: templatePath })],
        new Map(),
        {
          components: [],
        } as never,
      );

      expect(result.failed).toEqual([]);
      expect(result.valid[0].result.pageVariant).toBeNull();
    } finally {
      await fs.rm(temporaryDirectory, { recursive: true, force: true });
    }
  });

  it('reports legacy state pointer failures without repeating template name and path', async () => {
    const temporaryDirectory = await fs.mkdtemp(
      path.join(os.tmpdir(), 'prepare-content-template-'),
    );
    const templatePath = path.join(
      temporaryDirectory,
      'node.article.full.json',
    );

    try {
      await fs.writeFile(
        templatePath,
        JSON.stringify({
          label: 'Article legacy state',
          entityType: 'node',
          bundle: 'article',
          viewMode: 'full',
          elements: {
            button: {
              props: {
                label: { $state: 'title' },
              },
            },
          },
        }),
        'utf-8',
      );

      const result = await prepareContentTemplates(
        [mockDiscoveredContentTemplate({ path: templatePath })],
        new Map(),
        {
          components: [],
        } as never,
      );

      expect(result.valid).toEqual([]);
      expect(result.failed).toHaveLength(1);
      expect(result.failed[0].error.message).toBe(
        'Legacy "$state" pointers are no longer supported in authored files. Run `canvas pull` to regenerate, or replace each pointer with a prop-source object (e.g. {"sourceType":"entity-field","expression":"…"}). Affected props: button.label.',
      );
      expect(result.failed[0].error.message).not.toContain(
        'Cannot push content template',
      );
      expect(result.failed[0].error.message).not.toContain(templatePath);
    } finally {
      await fs.rm(temporaryDirectory, { recursive: true, force: true });
    }
  });
});

describe('collectContentTemplateResults', () => {
  it('collects failed push results with the label and file name', () => {
    const templates = [mockDiscoveredContentTemplate()];

    const results = collectContentTemplateResults(
      [{ success: false, error: new Error('API error'), index: 0 }],
      [],
      templates,
    );

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(false);
    expect(results[0].itemName).toBe('Article full (node.article.full.json)');
    expect(results[0].details?.[0].content).toBe('API error');
  });

  it('falls back to the file name when no template label is available', () => {
    const templates = [
      mockDiscoveredContentTemplate({
        name: 'node.article.full',
        label: null,
      }),
    ];

    const results = collectContentTemplateResults(
      [],
      [{ index: 0, error: new Error('Invalid JSON') }],
      templates,
    );

    expect(results).toHaveLength(1);
    expect(results[0].success).toBe(false);
    expect(results[0].itemName).toBe('node.article.full.json');
    expect(results[0].details?.[0].content).toBe('Invalid JSON');
  });
});
