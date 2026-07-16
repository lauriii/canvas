import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it, vi } from 'vitest';

import { contentTemplateToAuthored } from './content-templates';
import {
  collectContentTemplateResults,
  prepareContentTemplates,
  pushContentTemplates,
} from './prepare-content-templates-push';

import type { DiscoveredContentTemplate } from '@drupal-canvas/discovery';
import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';
import type {
  ContentTemplate,
  ContentTemplateListItem,
  ExposedSlots,
} from '../types/ContentTemplate';

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

describe('exposed_slots round-trip', () => {
  // A server payload with two exposed slots so the round-trip covers a
  // multi-slot map.
  const exposedSlots: ExposedSlots = {
    main: {
      component_uuid: '11111111-1111-4111-8111-111111111111',
      slot_name: 'content',
      label: 'Main content',
    },
    sidebar: {
      component_uuid: '22222222-2222-4222-8222-222222222222',
      slot_name: 'aside',
      label: 'Sidebar',
    },
  };

  const serverTemplate: ContentTemplate = {
    id: 'node.article.full',
    label: 'Article full',
    status: true,
    entityType: 'node',
    bundle: 'article',
    viewMode: 'full',
    component_tree: [],
    exposed_slots: exposedSlots,
  };

  it('preserves exposed_slots byte-for-byte through pull → push (create)', async () => {
    // Pull: server payload → authored on-disk file.
    const authored = contentTemplateToAuthored(serverTemplate);
    expect(authored.exposedSlots).toEqual(exposedSlots);

    // Push: authored file → server request body.
    let createdBody: { exposed_slots?: ExposedSlots } | undefined;
    const createContentTemplate = vi.fn(async (body) => {
      createdBody = body;
      return serverTemplate;
    });
    const updateContentTemplate = vi.fn();

    await pushContentTemplates(
      [
        {
          index: 0,
          result: {
            id: serverTemplate.id,
            label: serverTemplate.label,
            entityTypeId: serverTemplate.entityType,
            bundle: serverTemplate.bundle,
            viewMode: serverTemplate.viewMode,
            components: [] satisfies CanvasComponentTree,
            exposedSlots: authored.exposedSlots,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map(),
      { createContentTemplate, updateContentTemplate },
    );

    expect(createContentTemplate).toHaveBeenCalledTimes(1);
    expect(updateContentTemplate).not.toHaveBeenCalled();
    // The map survives unchanged.
    expect(createdBody?.exposed_slots).toEqual(exposedSlots);
    expect(JSON.stringify(createdBody?.exposed_slots)).toBe(
      JSON.stringify(serverTemplate.exposed_slots),
    );
  });

  it('sends exposed_slots on the update path when the template already exists', async () => {
    const authored = contentTemplateToAuthored(serverTemplate);

    let updatedBody: { exposed_slots?: ExposedSlots } | undefined;
    const createContentTemplate = vi.fn();
    const updateContentTemplate = vi.fn(async (_id, body) => {
      updatedBody = body;
      return serverTemplate;
    });

    const remote: ContentTemplateListItem = {
      id: serverTemplate.id,
      label: serverTemplate.label,
      status: serverTemplate.status,
      entityType: serverTemplate.entityType,
      bundle: serverTemplate.bundle,
      viewMode: serverTemplate.viewMode,
    };

    await pushContentTemplates(
      [
        {
          index: 0,
          result: {
            id: serverTemplate.id,
            label: serverTemplate.label,
            entityTypeId: serverTemplate.entityType,
            bundle: serverTemplate.bundle,
            viewMode: serverTemplate.viewMode,
            components: [] satisfies CanvasComponentTree,
            exposedSlots: authored.exposedSlots,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map([[serverTemplate.id, remote]]),
      { createContentTemplate, updateContentTemplate },
    );

    expect(updateContentTemplate).toHaveBeenCalledTimes(1);
    expect(createContentTemplate).not.toHaveBeenCalled();
    expect(updatedBody?.exposed_slots).toEqual(exposedSlots);
  });

  it('omits exposed_slots from the request body when a template has none', async () => {
    const authored = contentTemplateToAuthored({
      ...serverTemplate,
      exposed_slots: {},
    });
    // An empty map is not persisted to the authored file.
    expect(authored.exposedSlots).toBeUndefined();

    let createdBody: { exposed_slots?: ExposedSlots } | undefined;
    const createContentTemplate = vi.fn(async (body) => {
      createdBody = body;
      return serverTemplate;
    });

    await pushContentTemplates(
      [
        {
          index: 0,
          result: {
            id: serverTemplate.id,
            label: serverTemplate.label,
            entityTypeId: serverTemplate.entityType,
            bundle: serverTemplate.bundle,
            viewMode: serverTemplate.viewMode,
            components: [] satisfies CanvasComponentTree,
            exposedSlots: authored.exposedSlots,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map(),
      { createContentTemplate, updateContentTemplate: vi.fn() },
    );

    expect(createdBody).toBeDefined();
    expect('exposed_slots' in (createdBody ?? {})).toBe(false);
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
