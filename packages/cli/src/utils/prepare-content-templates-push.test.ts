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
  loadComponentsMetadata: vi.fn(async () => new Map()),
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
});

describe('prepareContentTemplates', () => {
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
