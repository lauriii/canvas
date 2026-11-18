import { afterEach, describe, expect, it, vi } from 'vitest';

import {
  buildContentEditActions,
  buildEntityAddFormUrl,
  getAllAddNewOptions,
  getTemplatedEntityGroups,
} from '@/features/navigator/templatedContent';

import type { ExposedSlotServerDefinition } from '@/features/layout/exposedSlots';
import type { TemplateViewMode } from '@/services/componentAndLayout';

// Builds a content-templates listing entry for one view mode with the given
// exposed slots (server, snake_case shape).
const viewMode = (
  entityType: string,
  bundle: string,
  exposed_slots: Record<string, ExposedSlotServerDefinition>,
): TemplateViewMode => ({
  entityType,
  bundle,
  viewMode: 'full',
  viewModeLabel: 'Full',
  label: 'Full',
  status: true,
  id: `${entityType}.${bundle}.full`,
  exposed_slots,
});

const activeSlot: ExposedSlotServerDefinition = {
  component_uuid: 'c1',
  slot_name: 'body',
  label: 'Body',
};

describe('getTemplatedEntityGroups', () => {
  it('returns an empty array when templates are missing', () => {
    expect(getTemplatedEntityGroups(undefined)).toEqual([]);
  });

  it('excludes Canvas pages and lists templated bundles with an exposed slot', () => {
    const groups = getTemplatedEntityGroups({
      canvas_page: {
        label: 'Canvas Page',
        entityTypeLabel: 'Canvas Pages',
        bundles: {
          canvas_page: {
            label: 'Canvas Page',
            // Even if a page template had exposed slots, canvas_page is listed
            // separately and must never appear as a templated group.
            viewModes: {
              full: viewMode('canvas_page', 'canvas_page', {
                hero: activeSlot,
              }),
            },
          },
        },
      },
      node: {
        label: 'Content types',
        entityTypeLabel: 'Content',
        bundles: {
          article: {
            label: 'Article',
            viewModes: {
              full: viewMode('node', 'article', { body: activeSlot }),
            },
          },
        },
      },
    });
    expect(groups).toHaveLength(1);
    expect(groups[0].entityType).toBe('node');
    // Single active bundle -> group title is the bundle label.
    expect(groups[0].title).toBe('Article');
    expect(groups[0].bundles).toEqual([
      { bundle: 'article', label: 'Article' },
    ]);
  });

  it('excludes bundles whose templates expose no slot', () => {
    // Slots are exposed or absent; there is no "disabled" flag. A bundle whose
    // view modes carry an empty `exposed_slots` map contributes no group.
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content types',
        entityTypeLabel: 'Content',
        bundles: {
          no_slots: {
            label: 'No slots',
            viewModes: { full: viewMode('node', 'no_slots', {}) },
          },
        },
      },
    });
    expect(groups).toEqual([]);
  });

  it('uses the entity type label when several bundles are active', () => {
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content types',
        entityTypeLabel: 'Content',
        bundles: {
          article: {
            label: 'Article',
            viewModes: {
              full: viewMode('node', 'article', { body: activeSlot }),
            },
          },
          landing: {
            label: 'Landing page',
            viewModes: {
              full: viewMode('node', 'landing', { hero: activeSlot }),
            },
          },
        },
      },
    });
    expect(groups).toHaveLength(1);
    // Multiple active bundles -> fall back to the entity type's collection
    // label (not the bundles' "Content types") because the content list
    // carries no per-item bundle to sub-group by.
    expect(groups[0].title).toBe('Content');
    expect(groups[0].bundles).toEqual([
      { bundle: 'article', label: 'Article' },
      { bundle: 'landing', label: 'Landing page' },
    ]);
  });
});

describe('buildEntityAddFormUrl', () => {
  it('builds the Drupal node add-form URL', () => {
    expect(buildEntityAddFormUrl('/', 'node', 'article')).toBe(
      '/node/add/article',
    );
  });

  it('normalizes a base URL without a trailing slash and a subdirectory', () => {
    expect(buildEntityAddFormUrl('/sub', 'node', 'article')).toBe(
      '/sub/node/add/article',
    );
    expect(buildEntityAddFormUrl(undefined, 'node', 'page')).toBe(
      '/node/add/page',
    );
  });

  it('returns null for unsupported entity types', () => {
    expect(buildEntityAddFormUrl('/', 'block_content', 'basic')).toBe(null);
  });
});

describe('buildContentEditActions', () => {
  const entity = {
    id: '7',
    editUrl: '/node/7/edit',
    canvasUrl: '/canvas/editor/node/7',
    manageFieldsUrl: '/admin/structure/types/manage/article/fields',
  };

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns no actions without an entity type or entity', () => {
    const navigateToEditor = vi.fn();
    expect(
      buildContentEditActions(navigateToEditor, undefined, entity),
    ).toEqual([]);
    expect(
      buildContentEditActions(navigateToEditor, 'node', undefined),
    ).toEqual([]);
  });

  it('offers every action when the server sent every URL', () => {
    const navigateToEditor = vi.fn();
    const actions = buildContentEditActions(navigateToEditor, 'node', entity);
    expect(
      actions.map(({ key, label, external }) => [key, label, external]),
    ).toEqual([
      ['slots', 'Edit in Canvas', false],
      ['content', 'Edit CMS content', true],
      ['fields', 'Manage fields', true],
    ]);
  });

  it('gates each action on its own URL', () => {
    const navigateToEditor = vi.fn();
    const keys = (target: Parameters<typeof buildContentEditActions>[2]) =>
      buildContentEditActions(navigateToEditor, 'node', target).map(
        ({ key }) => key,
      );
    // No exposed slots on the bundle's template: the server omits the Canvas
    // URL, so only the CMS edit and fields actions are offered.
    expect(
      keys({
        id: '7',
        editUrl: entity.editUrl,
        canvasUrl: null,
        manageFieldsUrl: entity.manageFieldsUrl,
      }),
    ).toEqual(['content', 'fields']);
    // No update access: the server omits both edit URLs.
    expect(keys({ id: '7', manageFieldsUrl: entity.manageFieldsUrl })).toEqual([
      'fields',
    ]);
    // No field administration access.
    expect(
      keys({
        id: '7',
        editUrl: entity.editUrl,
        canvasUrl: entity.canvasUrl,
        manageFieldsUrl: null,
      }),
    ).toEqual(['slots', 'content']);
    expect(keys({ id: '7' })).toEqual([]);
  });

  it('runs the internal action in the SPA and the external ones in a new tab', () => {
    const navigateToEditor = vi.fn();
    const open = vi.spyOn(window, 'open').mockImplementation(() => null);
    const actions = buildContentEditActions(navigateToEditor, 'node', entity);
    const run = (key: string) =>
      actions.find((action) => action.key === key)?.run();

    run('slots');
    expect(navigateToEditor).toHaveBeenCalledWith('node', '7');
    expect(open).not.toHaveBeenCalled();

    run('content');
    expect(open).toHaveBeenLastCalledWith(
      '/node/7/edit',
      '_blank',
      'noopener,noreferrer',
    );
    run('fields');
    expect(open).toHaveBeenLastCalledWith(
      '/admin/structure/types/manage/article/fields',
      '_blank',
      'noopener,noreferrer',
    );
    expect(navigateToEditor).toHaveBeenCalledTimes(1);
  });
});

describe('getAllAddNewOptions', () => {
  it('returns no options without create operations', () => {
    expect(getAllAddNewOptions(undefined, '/')).toEqual([]);
    expect(getAllAddNewOptions({}, '/')).toEqual([]);
  });

  it('lists one option per creatable bundle with its add-form URL', () => {
    expect(
      getAllAddNewOptions(
        { node: { article: 'Article', landing: 'Landing page' } },
        '/sub',
      ),
    ).toEqual([
      { bundle: 'article', label: 'Article', url: '/sub/node/add/article' },
      {
        bundle: 'landing',
        label: 'Landing page',
        url: '/sub/node/add/landing',
      },
    ]);
  });

  it('excludes Canvas pages and entity types without an add-form URL', () => {
    expect(
      getAllAddNewOptions(
        {
          // Created from the Pages panel, not here.
          canvas_page: { canvas_page: 'Page' },
          // No add-form URL is known for non-node entity types.
          block_content: { basic: 'Basic block' },
          node: { article: 'Article' },
        },
        '/',
      ),
    ).toEqual([
      { bundle: 'article', label: 'Article', url: '/node/add/article' },
    ]);
  });
});
