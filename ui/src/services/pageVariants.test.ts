import { describe, expect, it } from 'vitest';

import {
  buildCreateVariantPayload,
  buildDuplicateVariantPayload,
  buildMarkerTreeItem,
  generateUniqueVariantId,
  getPageContentMarkerVersion,
  PAGE_CONTENT_MARKER_ID,
  slugifyVariantId,
} from '@/services/pageVariants';

import type { ComponentsList } from '@/types/Component';
import type { PageVariant } from '@/types/PageVariant';

describe('slugifyVariantId', () => {
  it('lowercases and replaces runs of non-alphanumerics with a single underscore', () => {
    expect(slugifyVariantId('My Cool Variant!')).toBe('my_cool_variant');
  });

  it('trims leading and trailing underscores', () => {
    expect(slugifyVariantId('  --Landing--  ')).toBe('landing');
  });

  it('falls back to "variant" when nothing usable remains', () => {
    expect(slugifyVariantId('***')).toBe('variant');
  });
});

describe('generateUniqueVariantId', () => {
  it('returns the base slug when it is free', () => {
    expect(generateUniqueVariantId('Homepage', [])).toBe('homepage');
  });

  it('appends the first free numeric suffix on collision', () => {
    expect(
      generateUniqueVariantId('Homepage', ['homepage', 'homepage_2']),
    ).toBe('homepage_3');
  });
});

describe('getPageContentMarkerVersion', () => {
  it('reads the marker version from the component library', () => {
    const components = {
      [PAGE_CONTENT_MARKER_ID]: { version: 'abc123' },
    } as unknown as ComponentsList;
    expect(getPageContentMarkerVersion(components)).toBe('abc123');
  });

  it('returns undefined when the marker or list is absent', () => {
    expect(getPageContentMarkerVersion(undefined)).toBeUndefined();
    expect(getPageContentMarkerVersion({} as ComponentsList)).toBeUndefined();
  });
});

describe('buildMarkerTreeItem', () => {
  it('builds a marker instance with the given version and uuid', () => {
    expect(buildMarkerTreeItem('v1', 'uuid-1')).toEqual({
      uuid: 'uuid-1',
      component_id: PAGE_CONTENT_MARKER_ID,
      component_version: 'v1',
      inputs: [],
    });
  });
});

describe('buildCreateVariantPayload', () => {
  it('seeds a new variant with exactly one marker and a unique id', () => {
    const payload = buildCreateVariantPayload({
      label: '  Landing Page ',
      description: '  Marketing landing ',
      existingIds: ['landing_page'],
      markerVersion: 'v1',
      uuid: 'uuid-1',
    });

    expect(payload).toEqual({
      id: 'landing_page_2',
      label: 'Landing Page',
      description: 'Marketing landing',
      component_tree: [
        {
          uuid: 'uuid-1',
          component_id: PAGE_CONTENT_MARKER_ID,
          component_version: 'v1',
          inputs: [],
        },
      ],
    });
  });
});

describe('buildDuplicateVariantPayload', () => {
  const source: PageVariant = {
    id: 'homepage',
    label: 'Homepage',
    description: 'The default layout.',
    status: true,
    component_tree: [
      {
        uuid: 'uuid-marker',
        component_id: PAGE_CONTENT_MARKER_ID,
        component_version: 'v1',
        inputs: [],
      },
    ],
  };

  it('copies the source tree verbatim and derives a "(copy)" label and unique id', () => {
    const payload = buildDuplicateVariantPayload({
      source,
      existingIds: ['homepage', 'homepage_copy'],
    });

    expect(payload.id).toBe('homepage_copy_2');
    expect(payload.label).toBe('Homepage (copy)');
    expect(payload.description).toBe('The default layout.');
    expect(payload.component_tree).toEqual(source.component_tree);
    // The tree items are cloned, not shared by reference.
    expect(payload.component_tree[0]).not.toBe(source.component_tree[0]);
  });

  it('honors an explicit label override', () => {
    const payload = buildDuplicateVariantPayload({
      source,
      existingIds: [],
      label: 'Campaign',
    });
    expect(payload.id).toBe('campaign');
    expect(payload.label).toBe('Campaign');
  });
});
