import { describe, expect, it } from 'vitest';

import {
  getAddNewOptions,
  getAllAddNewOptions,
  getContentNavigationTypeOptions,
  getTemplatedEntityGroups,
  resolveContentNavigationType,
} from '@/features/navigator/templatedContent';

import type { ExposedSlotServerDefinition } from '@/features/layout/exposedSlots';
import type { TemplateViewMode } from '@/services/componentAndLayout';

// Builds a content-templates listing entry for one view mode with the given
// status and exposed slots (server, snake_case shape).
const viewMode = (
  entityType: string,
  bundle: string,
  exposed_slots: Record<string, ExposedSlotServerDefinition>,
  status: boolean = true,
): TemplateViewMode => ({
  entityType,
  bundle,
  viewMode: 'full',
  viewModeLabel: 'Full',
  label: 'Full',
  status,
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

  it('excludes Canvas pages and lists templated bundles', () => {
    const groups = getTemplatedEntityGroups({
      canvas_page: {
        label: 'Canvas Page',
        bundles: {
          canvas_page: {
            label: 'Canvas Page',
            // Even though a page template is enabled, canvas_page is listed
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
        label: 'Content',
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

  it('includes bundles whose enabled template exposes no slot', () => {
    // Exposed slots are not required: a zero-slot templated bundle opens in
    // the editor with a locked canvas and an editable Content tab, so an
    // enabled full-view template alone qualifies the bundle.
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content',
        bundles: {
          no_slots: {
            label: 'No slots',
            viewModes: { full: viewMode('node', 'no_slots', {}) },
          },
        },
      },
    });
    expect(groups).toHaveLength(1);
    expect(groups[0].bundles).toEqual([
      { bundle: 'no_slots', label: 'No slots' },
    ]);
  });

  it('excludes bundles whose full-view template is disabled', () => {
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content',
        bundles: {
          article: {
            label: 'Article',
            viewModes: {
              full: viewMode('node', 'article', { body: activeSlot }, false),
            },
          },
        },
      },
    });
    expect(groups).toEqual([]);
  });

  it('excludes bundles with a template only for another view mode', () => {
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content',
        bundles: {
          article: {
            label: 'Article',
            viewModes: {
              teaser: {
                ...viewMode('node', 'article', {}),
                viewMode: 'teaser',
                id: 'node.article.teaser',
              },
            },
          },
        },
      },
    });
    expect(groups).toEqual([]);
  });

  it('uses the entity type label when several bundles are active', () => {
    const groups = getTemplatedEntityGroups({
      node: {
        label: 'Content',
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
              full: viewMode('node', 'landing', {}),
            },
          },
        },
      },
    });
    expect(groups).toHaveLength(1);
    // Multiple active bundles -> fall back to the entity type label because the
    // content list carries no per-item bundle to sub-group by.
    expect(groups[0].title).toBe('Content');
    expect(groups[0].bundles).toEqual([
      { bundle: 'article', label: 'Article' },
      { bundle: 'landing', label: 'Landing page' },
    ]);
  });
});

describe('getAddNewOptions', () => {
  const group = {
    entityType: 'node',
    title: 'Content',
    bundles: [
      { bundle: 'article', label: 'Article' },
      { bundle: 'landing', label: 'Landing page' },
    ],
  };

  it('offers only bundles the user may create, as creation coordinates', () => {
    const options = getAddNewOptions(group, {
      node: { article: 'Create Article' },
    });
    expect(options).toEqual([
      { entityType: 'node', bundle: 'article', label: 'Article' },
    ]);
  });

  it('returns nothing when the user cannot create any bundle', () => {
    expect(getAddNewOptions(group, {})).toEqual([]);
    expect(getAddNewOptions(group, undefined)).toEqual([]);
  });

  it('supports any entity type, not only nodes', () => {
    // Creation goes through the generic Canvas content API, so no entity type
    // is dropped for lacking a derivable Drupal add-form URL.
    const blockGroup = {
      entityType: 'block_content',
      title: 'Blocks',
      bundles: [{ bundle: 'basic', label: 'Basic' }],
    };
    const options = getAddNewOptions(blockGroup, {
      block_content: { basic: 'Create Basic' },
    });
    expect(options).toEqual([
      { entityType: 'block_content', bundle: 'basic', label: 'Basic' },
    ]);
  });
});

describe('getAllAddNewOptions', () => {
  it('enumerates every creatable bundle except Canvas pages', () => {
    const options = getAllAddNewOptions({
      canvas_page: { canvas_page: 'Create page' },
      node: { article: 'Article', landing: 'Landing page' },
      block_content: { basic: 'Basic' },
    });
    expect(options).toEqual([
      { entityType: 'node', bundle: 'article', label: 'Article' },
      { entityType: 'node', bundle: 'landing', label: 'Landing page' },
      { entityType: 'block_content', bundle: 'basic', label: 'Basic' },
    ]);
  });

  it('returns nothing without create operations', () => {
    expect(getAllAddNewOptions(undefined)).toEqual([]);
    expect(getAllAddNewOptions({})).toEqual([]);
  });
});

describe('getContentNavigationTypeOptions', () => {
  it('lists Pages first, then one option per templated entity type', () => {
    const options = getContentNavigationTypeOptions([
      { entityType: 'node', title: 'Article', bundles: [] },
      { entityType: 'block_content', title: 'Blocks', bundles: [] },
    ]);
    expect(options).toEqual([
      { entityType: 'canvas_page', label: 'Pages' },
      { entityType: 'node', label: 'Article' },
      { entityType: 'block_content', label: 'Blocks' },
    ]);
  });

  it('offers only Pages when no templated entity types exist', () => {
    expect(getContentNavigationTypeOptions([])).toEqual([
      { entityType: 'canvas_page', label: 'Pages' },
    ]);
  });
});

describe('resolveContentNavigationType', () => {
  const options = [
    { entityType: 'canvas_page', label: 'Pages' },
    { entityType: 'node', label: 'Article' },
  ];

  it('keeps an available selection', () => {
    expect(resolveContentNavigationType('node', options)).toBe('node');
  });

  it('falls back to Canvas pages for an unavailable selection', () => {
    expect(resolveContentNavigationType('block_content', options)).toBe(
      'canvas_page',
    );
    // While the templates query still loads there are no options at all.
    expect(resolveContentNavigationType('node', [])).toBe('canvas_page');
  });
});
