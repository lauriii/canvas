import { describe, expect, it } from 'vitest';

import {
  buildEntityAddFormUrl,
  getAddNewOptions,
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

  it('excludes bundles whose templates expose no slot', () => {
    // Slots are exposed or absent; there is no "disabled" flag. A bundle whose
    // view modes carry an empty `exposed_slots` map contributes no group.
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
              full: viewMode('node', 'landing', { hero: activeSlot }),
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

describe('getAddNewOptions', () => {
  const group = {
    entityType: 'node',
    title: 'Content',
    bundles: [
      { bundle: 'article', label: 'Article' },
      { bundle: 'landing', label: 'Landing page' },
    ],
  };

  it('offers only bundles the user may create, with add-form URLs', () => {
    const options = getAddNewOptions(
      group,
      { node: { article: 'Create Article' } },
      '/',
    );
    expect(options).toEqual([
      { bundle: 'article', label: 'Article', url: '/node/add/article' },
    ]);
  });

  it('returns nothing when the user cannot create any bundle', () => {
    expect(getAddNewOptions(group, {}, '/')).toEqual([]);
    expect(getAddNewOptions(group, undefined, '/')).toEqual([]);
  });

  it('drops bundles with no derivable add-form URL', () => {
    const blockGroup = {
      entityType: 'block_content',
      title: 'Blocks',
      bundles: [{ bundle: 'basic', label: 'Basic' }],
    };
    const options = getAddNewOptions(
      blockGroup,
      { block_content: { basic: 'Create Basic' } },
      '/',
    );
    expect(options).toEqual([]);
  });
});
