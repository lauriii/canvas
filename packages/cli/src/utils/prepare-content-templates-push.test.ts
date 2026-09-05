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
        createSlotField: vi.fn(),
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
        createSlotField: vi.fn(),
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
      createSlotField: vi.fn(),
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
      {
        createContentTemplate,
        updateContentTemplate,
        createSlotField: vi.fn(),
      },
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
      {
        createContentTemplate: vi.fn(),
        updateContentTemplate,
        createSlotField: vi.fn(),
      },
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

  it('remaps exposed-slot component_uuid through the element key→UUID map', async () => {
    const temporaryDirectory = await fs.mkdtemp(
      path.join(os.tmpdir(), 'prepare-content-template-'),
    );
    const templatePath = path.join(
      temporaryDirectory,
      'node.article.full.json',
    );

    try {
      // The element is keyed by a friendly alias, not a UUID: the tree builder
      // mints a fresh UUID for it, and the exposed slot's component_uuid must
      // follow to that same UUID.
      await fs.writeFile(
        templatePath,
        JSON.stringify({
          label: 'Article full',
          entityType: 'node',
          bundle: 'article',
          viewMode: 'full',
          elements: {
            hero: { type: 'sdc.canvas_test.hero', props: {} },
          },
          exposedSlots: {
            canvas_slot_main: {
              component_uuid: 'hero',
              slot_name: 'content',
              label: 'Main',
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

      expect(result.failed).toEqual([]);
      const prepared = result.valid[0].result;
      const heroUuid = prepared.components[0].uuid;
      expect(heroUuid).toMatch(/^[0-9a-f-]{36}$/);
      expect(prepared.exposedSlots?.canvas_slot_main.component_uuid).toBe(
        heroUuid,
      );
    } finally {
      await fs.rm(temporaryDirectory, { recursive: true, force: true });
    }
  });
});

describe('exposed_slots round-trip', () => {
  // A server payload with two exposed slots so the round-trip covers a
  // multi-slot map.
  const exposedSlots: ExposedSlots = {
    canvas_slot_main: {
      component_uuid: '11111111-1111-4111-8111-111111111111',
      slot_name: 'content',
      label: 'Main content',
    },
    // A reused pre-existing component_tree field: a valid exposed-slot key
    // without the canvas_slot_ prefix. It cannot be provisioned through the
    // slot-field endpoint and must already exist on the target.
    field_existing_area: {
      component_uuid: '22222222-2222-4222-8222-222222222222',
      slot_name: 'aside',
      label: 'Existing area',
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

    // Push: authored file → server request bodies. A fresh-site create
    // sequences: create without slots, provision each backing field, then
    // update with the slots (a template referencing exposed slots only
    // validates once the fields exist).
    let createdBody: { exposed_slots?: ExposedSlots } | undefined;
    let updatedBody: { exposed_slots?: ExposedSlots } | undefined;
    const createContentTemplate = vi.fn(async (body) => {
      createdBody = body;
      return serverTemplate;
    });
    const updateContentTemplate = vi.fn(async (_id, body) => {
      updatedBody = body;
      return serverTemplate;
    });
    const createSlotField = vi.fn();

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
            pageVariant: null,
            exposedSlots: authored.exposedSlots,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map(),
      { createContentTemplate, updateContentTemplate, createSlotField },
    );

    expect(createContentTemplate).toHaveBeenCalledTimes(1);
    expect('exposed_slots' in (createdBody ?? {})).toBe(false);
    // Only the canvas_slot_-prefixed field is provisioned; the reused
    // pre-existing field cannot be created through the slot-field endpoint.
    expect(createSlotField).toHaveBeenCalledTimes(1);
    expect(createSlotField).toHaveBeenCalledWith(
      serverTemplate.id,
      'canvas_slot_main',
      'Main content',
    );
    expect(updateContentTemplate).toHaveBeenCalledTimes(1);
    // The map survives unchanged.
    expect(updatedBody?.exposed_slots).toEqual(exposedSlots);
    expect(JSON.stringify(updatedBody?.exposed_slots)).toBe(
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
    const createSlotField = vi.fn();

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
            pageVariant: null,
            exposedSlots: authored.exposedSlots,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map([[serverTemplate.id, remote]]),
      { createContentTemplate, updateContentTemplate, createSlotField },
    );

    expect(updateContentTemplate).toHaveBeenCalledTimes(1);
    expect(createContentTemplate).not.toHaveBeenCalled();
    // The update path provisions too: the authored file may reference slot
    // fields the target site has never seen (create-if-missing, 409 is fine).
    // Only the prefixed field can be provisioned.
    expect(createSlotField).toHaveBeenCalledTimes(1);
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
            pageVariant: null,
            exposedSlots: authored.exposedSlots,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map(),
      {
        createContentTemplate,
        updateContentTemplate: vi.fn(),
        createSlotField: vi.fn(),
      },
    );

    expect(createdBody).toBeDefined();
    expect('exposed_slots' in (createdBody ?? {})).toBe(false);
  });

  it('sends an empty exposed_slots map on update when the template has none', async () => {
    // Pull represents a slot-free template by omitting the property from the
    // authored file, so the update must send the empty map to detach slots
    // still present on the target.
    let updatedBody: { exposed_slots?: ExposedSlots } | undefined;
    const updateContentTemplate = vi.fn(async (_id, body) => {
      updatedBody = body;
      return serverTemplate;
    });
    const createSlotField = vi.fn();

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
            pageVariant: null,
            exposedSlots: undefined,
            filePath: '/tmp/content-templates/node.article.full.json',
          },
        },
      ],
      new Map([[serverTemplate.id, remote]]),
      {
        createContentTemplate: vi.fn(),
        updateContentTemplate,
        createSlotField,
      },
    );

    expect(updateContentTemplate).toHaveBeenCalledTimes(1);
    expect(updatedBody?.exposed_slots).toEqual({});
    // No slots to provision.
    expect(createSlotField).not.toHaveBeenCalled();
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
