/**
 * Pure helpers for the full-page content browser (/canvas/content).
 *
 * The list API paginates per entity type, so the browser fetches the first
 * server page (50 items) of every listed type, merges them client-side, sorts
 * the merged set, and paginates it 25 per page. This is a documented v1
 * limitation: entities beyond a type's first server page (under the current
 * ordering) are not reachable from the merged view. These functions are
 * side-effect free so the merging, sorting, and pagination logic can be unit
 * tested without rendering.
 */

import { PAGE_ENTITY_TYPE } from '@/features/navigator/templatedContent';

import type { TemplatedEntityGroup } from '@/features/navigator/templatedContent';
import type { ContentStub } from '@/types/Content';

/** The browser's client-side page size. */
export const CONTENT_BROWSER_PAGE_SIZE = 25;

/**
 * The sortable columns. Title, Created, and Updated map to the list API's
 * title/created/changed sort keys; Type and Author are not sortable because
 * the API cannot order by them.
 */
export type ContentSortKey = 'title' | 'created' | 'changed';

export type ContentSortDirection = 'asc' | 'desc';

export interface ContentSort {
  key: ContentSortKey;
  direction: ContentSortDirection;
}

/** Newest-updated first, matching the list API's default ordering. */
export const DEFAULT_CONTENT_SORT: ContentSort = {
  key: 'changed',
  direction: 'desc',
};

/**
 * Maps a sort state to the list API's JSON:API-style sort parameter, so each
 * per-type server page holds that type's top items under the ordering the
 * merged view displays.
 */
export function toApiSort(sort: ContentSort): string {
  return sort.direction === 'desc' ? `-${sort.key}` : sort.key;
}

/**
 * The sort state after a header click: clicking the active column toggles its
 * direction; clicking another column starts it at its natural direction
 * (A to Z for Title, newest first for the date columns).
 */
export function getNextSort(
  current: ContentSort,
  key: ContentSortKey,
): ContentSort {
  if (current.key === key) {
    return {
      key,
      direction: current.direction === 'asc' ? 'desc' : 'asc',
    };
  }
  return { key, direction: key === 'title' ? 'asc' : 'desc' };
}

/** The title shown in the browser: an auto-saved draft label wins over the stored title. */
export function getDisplayTitle(item: ContentStub): string {
  return item.autoSaveLabel ?? item.title;
}

/** One per-entity-type list the browser fetches. */
export interface ContentListSource {
  entityType: string;
  /** The API's bundle filter, set when a single templated type is selected. */
  bundle?: string;
}

/** A stable identity for a source, used as fetch key and results-map key. */
export function getSourceKey(source: ContentListSource): string {
  return source.bundle
    ? `${source.entityType}:${source.bundle}`
    : source.entityType;
}

/** One option of the content-type filter ("All content" is the null filter). */
export interface ContentTypeFilterOption {
  value: string;
  label: string;
  entityType: string;
  /** Null for Canvas pages, whose single bundle is implied. */
  bundle: string | null;
}

/**
 * The content-type filter options: Canvas pages first, then every templated
 * bundle in listing order.
 */
export function getContentTypeFilterOptions(
  groups: TemplatedEntityGroup[],
): ContentTypeFilterOption[] {
  const options: ContentTypeFilterOption[] = [
    {
      value: PAGE_ENTITY_TYPE,
      label: 'Page',
      entityType: PAGE_ENTITY_TYPE,
      bundle: null,
    },
  ];
  for (const group of groups) {
    for (const { bundle, label } of group.bundles) {
      options.push({
        value: `${group.entityType}:${bundle}`,
        label,
        entityType: group.entityType,
        bundle,
      });
    }
  }
  return options;
}

/**
 * The lists to fetch for a filter selection: every listed entity type for
 * "All content" (null filter), or the single selected type, narrowed with the
 * API's bundle filter when the selection is a templated bundle.
 */
export function getContentListSources(
  groups: TemplatedEntityGroup[],
  filter: ContentTypeFilterOption | null,
): ContentListSource[] {
  if (filter === null) {
    return [
      { entityType: PAGE_ENTITY_TYPE },
      ...groups.map((group) => ({ entityType: group.entityType })),
    ];
  }
  return [
    filter.bundle === null
      ? { entityType: filter.entityType }
      : { entityType: filter.entityType, bundle: filter.bundle },
  ];
}

/** One fetched list awaiting merge, with the entity type its items belong to. */
export interface ContentListToMerge {
  entityType: string;
  items: ContentStub[];
}

/**
 * Merges the per-type lists into one flat list. Items missing `entityType`
 * (older payloads) are stamped with their source's, since row navigation and
 * test ids need it. Duplicate entity type + id pairs are dropped defensively,
 * keeping the first occurrence.
 */
export function mergeContentLists(lists: ContentListToMerge[]): ContentStub[] {
  const merged: ContentStub[] = [];
  const seen = new Set<string>();
  for (const { entityType, items } of lists) {
    for (const item of items) {
      const stamped = item.entityType ? item : { ...item, entityType };
      const key = `${stamped.entityType}:${String(stamped.id)}`;
      if (seen.has(key)) {
        continue;
      }
      seen.add(key);
      merged.push(stamped);
    }
  }
  return merged;
}

/**
 * Sorts the merged list client-side; this ordering is what the user sees.
 * Title compares the displayed title (auto-save label included),
 * case-insensitively. Items without the compared timestamp sort last in
 * either direction. Returns a new array.
 */
export function sortContentItems(
  items: ContentStub[],
  sort: ContentSort,
): ContentStub[] {
  const direction = sort.direction === 'asc' ? 1 : -1;
  return [...items].sort((a, b) => {
    if (sort.key === 'title') {
      return (
        direction *
        getDisplayTitle(a).localeCompare(getDisplayTitle(b), 'en', {
          sensitivity: 'base',
        })
      );
    }
    const aValue = a[sort.key];
    const bValue = b[sort.key];
    if (aValue == null && bValue == null) {
      return 0;
    }
    if (aValue == null) {
      return 1;
    }
    if (bValue == null) {
      return -1;
    }
    return direction * (aValue - bValue);
  });
}

/** One client-side page of the sorted merged list. */
export interface ContentBrowserPage {
  pageItems: ContentStub[];
  pageCount: number;
  /** The requested page clamped into range (0-based). */
  page: number;
  totalCount: number;
}

/**
 * Slices one 25-item page out of the sorted merged list. The requested page is
 * clamped so a filter or search that shrinks the list never strands the user
 * on an out-of-range page.
 */
export function paginateContentItems(
  items: ContentStub[],
  requestedPage: number,
  pageSize: number = CONTENT_BROWSER_PAGE_SIZE,
): ContentBrowserPage {
  const totalCount = items.length;
  const pageCount = Math.max(1, Math.ceil(totalCount / pageSize));
  const page = Math.min(Math.max(requestedPage, 0), pageCount - 1);
  return {
    pageItems: items.slice(page * pageSize, (page + 1) * pageSize),
    pageCount,
    page,
    totalCount,
  };
}

/** The status badge shown on a row. */
export interface ContentStatusBadge {
  label: 'Published' | 'Unpublished' | 'Draft';
  color: 'green' | 'gray' | 'blue';
}

/**
 * A never-saved entity is a draft regardless of its stored status; otherwise
 * the badge reflects the published flag.
 */
export function getStatusBadge(item: ContentStub): ContentStatusBadge {
  if (item.isNew) {
    return { label: 'Draft', color: 'blue' };
  }
  return item.status
    ? { label: 'Published', color: 'green' }
    : { label: 'Unpublished', color: 'gray' };
}

const mediumDateFormatter = new Intl.DateTimeFormat('en', {
  dateStyle: 'medium',
});

/**
 * Formats a Drupal timestamp (seconds) as a medium 'en' date, or an em dash
 * when the entity type does not carry the field.
 */
export function formatContentDate(
  timestamp: number | null | undefined,
): string {
  if (timestamp == null) {
    return '—';
  }
  return mediumDateFormatter.format(new Date(timestamp * 1000));
}

/** One creatable type + bundle pair for the "Create content" menu. */
export interface CreateContentOption {
  entityType: string;
  bundle: string;
  label: string;
}

/**
 * Every type + bundle the user may create, from
 * `drupalSettings.canvas.contentEntityCreateOperations` (entity type ->
 * bundle -> label, already access-checked server-side), Canvas pages first.
 * Enumerated directly rather than through `getAllAddNewOptions`, which
 * excludes Canvas pages by design.
 */
export function getCreateContentOptions(
  createOperations: Record<string, Record<string, string>> | undefined,
): CreateContentOption[] {
  const options: CreateContentOption[] = [];
  const pageLabel = createOperations?.[PAGE_ENTITY_TYPE]?.[PAGE_ENTITY_TYPE];
  if (pageLabel) {
    options.push({
      entityType: PAGE_ENTITY_TYPE,
      bundle: PAGE_ENTITY_TYPE,
      label: typeof pageLabel === 'string' ? pageLabel : 'Page',
    });
  }
  for (const [entityType, bundles] of Object.entries(createOperations ?? {})) {
    if (entityType === PAGE_ENTITY_TYPE) {
      continue;
    }
    for (const [bundle, label] of Object.entries(bundles)) {
      options.push({ entityType, bundle, label });
    }
  }
  return options;
}
