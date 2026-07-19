import { describe, expect, it } from 'vitest';

import {
  CONTENT_BROWSER_PAGE_SIZE,
  DEFAULT_CONTENT_SORT,
  formatContentDate,
  getContentListSources,
  getContentTypeFilterOptions,
  getCreateContentOptions,
  getDisplayTitle,
  getNextSort,
  getSourceKey,
  getStatusBadge,
  mergeContentLists,
  paginateContentItems,
  sortContentItems,
  toApiSort,
} from '@/components/contentBrowser/contentBrowserHelpers';

import type { TemplatedEntityGroup } from '@/features/navigator/templatedContent';
import type { ContentStub } from '@/types/Content';

// Builds a content stub with the browser-relevant fields defaulted.
const stub = (overrides: Partial<ContentStub> = {}): ContentStub => ({
  title: 'Untitled',
  path: '/untitled',
  internalPath: '/node/1',
  id: 1,
  status: true,
  autoSaveLabel: null,
  autoSavePath: '',
  links: {},
  ...overrides,
});

const groups: TemplatedEntityGroup[] = [
  {
    entityType: 'node',
    title: 'Content',
    bundles: [
      { bundle: 'article', label: 'Article' },
      { bundle: 'event', label: 'Event' },
    ],
  },
  {
    entityType: 'taxonomy_term',
    title: 'Tags',
    bundles: [{ bundle: 'tags', label: 'Tags' }],
  },
];

describe('toApiSort', () => {
  it('maps sort state to the JSON:API-style parameter', () => {
    expect(toApiSort({ key: 'title', direction: 'asc' })).toBe('title');
    expect(toApiSort({ key: 'changed', direction: 'desc' })).toBe('-changed');
  });
});

describe('getNextSort', () => {
  it('toggles the direction of the active column', () => {
    expect(getNextSort({ key: 'title', direction: 'asc' }, 'title')).toEqual({
      key: 'title',
      direction: 'desc',
    });
    expect(getNextSort({ key: 'title', direction: 'desc' }, 'title')).toEqual({
      key: 'title',
      direction: 'asc',
    });
  });

  it('starts a newly selected column at its natural direction', () => {
    expect(getNextSort(DEFAULT_CONTENT_SORT, 'title')).toEqual({
      key: 'title',
      direction: 'asc',
    });
    expect(getNextSort({ key: 'title', direction: 'asc' }, 'created')).toEqual({
      key: 'created',
      direction: 'desc',
    });
  });
});

describe('getDisplayTitle', () => {
  it('prefers the auto-saved draft label over the stored title', () => {
    expect(getDisplayTitle(stub({ title: 'Stored' }))).toBe('Stored');
    expect(
      getDisplayTitle(stub({ title: 'Stored', autoSaveLabel: 'Draft title' })),
    ).toBe('Draft title');
  });
});

describe('getContentTypeFilterOptions', () => {
  it('lists Canvas pages first, then every templated bundle', () => {
    expect(getContentTypeFilterOptions(groups)).toEqual([
      {
        value: 'canvas_page',
        label: 'Page',
        entityType: 'canvas_page',
        bundle: null,
      },
      {
        value: 'node:article',
        label: 'Article',
        entityType: 'node',
        bundle: 'article',
      },
      {
        value: 'node:event',
        label: 'Event',
        entityType: 'node',
        bundle: 'event',
      },
      {
        value: 'taxonomy_term:tags',
        label: 'Tags',
        entityType: 'taxonomy_term',
        bundle: 'tags',
      },
    ]);
  });
});

describe('getContentListSources', () => {
  it('fetches every listed entity type for the null (all content) filter', () => {
    expect(getContentListSources(groups, null)).toEqual([
      { entityType: 'canvas_page' },
      { entityType: 'node' },
      { entityType: 'taxonomy_term' },
    ]);
  });

  it('narrows to one source when a single type is selected', () => {
    const options = getContentTypeFilterOptions(groups);
    expect(getContentListSources(groups, options[0])).toEqual([
      { entityType: 'canvas_page' },
    ]);
    expect(getContentListSources(groups, options[1])).toEqual([
      { entityType: 'node', bundle: 'article' },
    ]);
  });

  it('derives a stable key per source', () => {
    expect(getSourceKey({ entityType: 'canvas_page' })).toBe('canvas_page');
    expect(getSourceKey({ entityType: 'node', bundle: 'article' })).toBe(
      'node:article',
    );
  });
});

describe('mergeContentLists', () => {
  it('flattens the lists and stamps missing entity types', () => {
    const merged = mergeContentLists([
      { entityType: 'canvas_page', items: [stub({ id: 1 })] },
      {
        entityType: 'node',
        items: [stub({ id: 1 }), stub({ id: 2, entityType: 'node' })],
      },
    ]);
    expect(merged).toHaveLength(3);
    expect(merged.map((item) => item.entityType)).toEqual([
      'canvas_page',
      'node',
      'node',
    ]);
  });

  it('drops duplicate entity type and id pairs, keeping the first', () => {
    const merged = mergeContentLists([
      { entityType: 'node', items: [stub({ id: 1, title: 'First' })] },
      { entityType: 'node', items: [stub({ id: 1, title: 'Second' })] },
    ]);
    expect(merged).toHaveLength(1);
    expect(merged[0].title).toBe('First');
  });
});

describe('sortContentItems', () => {
  const items = [
    stub({ id: 1, title: 'banana', created: 30, changed: null }),
    stub({ id: 2, title: 'Apple', created: null, changed: 10 }),
    stub({ id: 3, title: 'Zebra', autoSaveLabel: 'aardvark', changed: 20 }),
  ];

  it('sorts by the displayed title, case-insensitively', () => {
    const sorted = sortContentItems(items, { key: 'title', direction: 'asc' });
    // The draft label "aardvark" wins over the stored "Zebra".
    expect(sorted.map((item) => item.id)).toEqual([3, 2, 1]);
    const reversed = sortContentItems(items, {
      key: 'title',
      direction: 'desc',
    });
    expect(reversed.map((item) => item.id)).toEqual([1, 2, 3]);
  });

  it('sorts by timestamp with missing values last in either direction', () => {
    expect(
      sortContentItems(items, { key: 'changed', direction: 'desc' }).map(
        (item) => item.id,
      ),
    ).toEqual([3, 2, 1]);
    expect(
      sortContentItems(items, { key: 'changed', direction: 'asc' }).map(
        (item) => item.id,
      ),
    ).toEqual([2, 3, 1]);
    // Ids 2 (null) and 3 (undefined) both lack `created` and keep their
    // relative order after the only dated item.
    expect(
      sortContentItems(items, { key: 'created', direction: 'asc' }).map(
        (item) => item.id,
      ),
    ).toEqual([1, 2, 3]);
  });

  it('returns a new array without mutating the input', () => {
    const input = [...items];
    sortContentItems(input, { key: 'title', direction: 'asc' });
    expect(input).toEqual(items);
  });
});

describe('paginateContentItems', () => {
  const items = Array.from({ length: 60 }, (_, index) =>
    stub({ id: index + 1 }),
  );

  it('slices 25-item pages', () => {
    const first = paginateContentItems(items, 0);
    expect(first.pageItems).toHaveLength(CONTENT_BROWSER_PAGE_SIZE);
    expect(first.pageItems[0].id).toBe(1);
    expect(first.pageCount).toBe(3);
    expect(first.totalCount).toBe(60);
    const second = paginateContentItems(items, 1);
    expect(second.pageItems[0].id).toBe(26);
    const last = paginateContentItems(items, 2);
    expect(last.pageItems).toHaveLength(10);
  });

  it('clamps an out-of-range page', () => {
    expect(paginateContentItems(items, 99).page).toBe(2);
    expect(paginateContentItems(items, -1).page).toBe(0);
    const empty = paginateContentItems([], 5);
    expect(empty.page).toBe(0);
    expect(empty.pageCount).toBe(1);
    expect(empty.pageItems).toEqual([]);
  });
});

describe('getStatusBadge', () => {
  it('marks never-saved entities as drafts regardless of status', () => {
    expect(getStatusBadge(stub({ isNew: true, status: false })).label).toBe(
      'Draft',
    );
    expect(getStatusBadge(stub({ isNew: true, status: true })).label).toBe(
      'Draft',
    );
  });

  it('reflects the published flag otherwise', () => {
    expect(getStatusBadge(stub({ status: true })).label).toBe('Published');
    expect(getStatusBadge(stub({ status: false })).label).toBe('Unpublished');
  });
});

describe('formatContentDate', () => {
  it('formats a Drupal seconds timestamp as a medium en date', () => {
    // Midday UTC keeps the calendar date stable in any test-runner timezone.
    const timestamp = Date.UTC(2026, 0, 15, 12) / 1000;
    expect(formatContentDate(timestamp)).toBe('Jan 15, 2026');
  });

  it('renders an em dash for missing timestamps', () => {
    expect(formatContentDate(null)).toBe('—');
    expect(formatContentDate(undefined)).toBe('—');
  });
});

describe('getCreateContentOptions', () => {
  it('returns nothing when the user may create nothing', () => {
    expect(getCreateContentOptions(undefined)).toEqual([]);
    expect(getCreateContentOptions({})).toEqual([]);
  });

  it('lists Canvas pages first, then the templated bundles', () => {
    expect(
      getCreateContentOptions({
        node: { article: 'Article', event: 'Event' },
        canvas_page: { canvas_page: 'Page' },
      }),
    ).toEqual([
      { entityType: 'canvas_page', bundle: 'canvas_page', label: 'Page' },
      { entityType: 'node', bundle: 'article', label: 'Article' },
      { entityType: 'node', bundle: 'event', label: 'Event' },
    ]);
  });

  it('omits Canvas pages when the user may not create them', () => {
    expect(getCreateContentOptions({ node: { article: 'Article' } })).toEqual([
      { entityType: 'node', bundle: 'article', label: 'Article' },
    ]);
  });
});
