/**
 * Pure helpers for the in-editor content navigator's templated-entity groups.
 *
 * Beyond Canvas pages, the navigator lists entities of templated bundles whose
 * content template exposes at least one active (non-disabled) slot, grouped so
 * they can be opened in the per-content editor (exposed-slots decision 6). The
 * source of truth for which bundles are templated is the content-templates
 * listing (`getContentTemplates`); the entities themselves come from the
 * per-entity-type content list endpoint (`/canvas/api/v0/content/{entityType}`,
 * which the server already access-filters to active-exposed-slot bundles).
 *
 * These functions are side-effect free so the grouping, filtering and
 * add-form-link logic can be unit tested without a store or a backend.
 */

import { countActiveExposedSlots } from '@/features/layout/exposedSlots';

import type { TemplateList } from '@/services/componentAndLayout';

/** The Canvas page entity type, which is listed separately (not templated). */
export const PAGE_ENTITY_TYPE = 'canvas_page';

/** One active templated bundle: its machine name and human label. */
export interface TemplatedBundle {
  bundle: string;
  label: string;
}

/**
 * A navigator group for one templated entity type.
 *
 * `title` is the bundle label when the entity type has a single active bundle
 * (the common case, e.g. a single "Article" template) and the entity type label
 * otherwise, because the content list payload carries no per-item bundle to sub
 * group by. Splitting a multi-bundle entity type into per-bundle sections would
 * require a `bundle` field on the content stub (server follow-up).
 */
export interface TemplatedEntityGroup {
  entityType: string;
  title: string;
  bundles: TemplatedBundle[];
}

/**
 * Derives the templated entity groups from the content-templates listing.
 *
 * Excludes Canvas pages and any bundle whose templates expose no active slot.
 *
 * @param templates
 *   The `getContentTemplates` response, or undefined while it loads.
 *
 * @return
 *   One group per templated entity type, in the listing's iteration order.
 */
export function getTemplatedEntityGroups(
  templates: TemplateList | undefined,
): TemplatedEntityGroup[] {
  if (!templates) {
    return [];
  }
  const groups: TemplatedEntityGroup[] = [];
  for (const [entityType, typeData] of Object.entries(templates)) {
    if (entityType === PAGE_ENTITY_TYPE) {
      continue;
    }
    const bundles: TemplatedBundle[] = [];
    for (const [bundle, bundleData] of Object.entries(typeData.bundles ?? {})) {
      const hasActiveExposedSlot = Object.values(
        bundleData.viewModes ?? {},
      ).some((viewMode) => countActiveExposedSlots(viewMode.exposed_slots) > 0);
      if (hasActiveExposedSlot) {
        bundles.push({ bundle, label: bundleData.label });
      }
    }
    if (bundles.length === 0) {
      continue;
    }
    const title = bundles.length === 1 ? bundles[0].label : typeData.label;
    groups.push({ entityType, title, bundles });
  }
  return groups;
}

/**
 * Builds the URL of Drupal's own creation form for a bundle.
 *
 * "Add new" links out to Drupal's entity creation form for v1 (required base
 * fields live there); the navigator does not build an in-Canvas creation flow.
 * Only `node` is supported, matching the current templated-entity scope; other
 * entity types return null (exposing a general add-form URL in `drupalSettings`
 * would be the server-side follow-up).
 *
 * @param baseUrl
 *   The Drupal base URL (`drupalSettings.path.baseUrl`, e.g. `/` or `/sub/`).
 * @param entityType
 *   The entity type machine name.
 * @param bundle
 *   The bundle machine name.
 *
 * @return
 *   The add-form URL, or null when the entity type is unsupported.
 */
export function buildEntityAddFormUrl(
  baseUrl: string | undefined,
  entityType: string,
  bundle: string,
): string | null {
  if (entityType !== 'node') {
    return null;
  }
  const base = baseUrl && baseUrl.length > 0 ? baseUrl : '/';
  const normalized = base.endsWith('/') ? base : `${base}/`;
  return `${normalized}node/add/${bundle}`;
}

/** A creatable bundle in a group: its label and Drupal add-form URL. */
export interface AddNewOption {
  bundle: string;
  label: string;
  url: string;
}

/**
 * The bundles in a group the current user may create.
 *
 * Gated by `drupalSettings.canvas.contentEntityCreateOperations`, which the
 * server computes from create access plus the presence of a Canvas field on the
 * bundle, so a bundle only becomes creatable once its template has provisioned
 * the field. Entries whose entity type has no derivable add-form URL are
 * dropped.
 *
 * @param group
 *   The templated entity group.
 * @param createOperations
 *   `contentEntityCreateOperations`: entity type -> bundle -> label.
 * @param baseUrl
 *   The Drupal base URL.
 *
 * @return
 *   The creatable bundles, with labels and add-form URLs.
 */
export function getAddNewOptions(
  group: TemplatedEntityGroup,
  createOperations: Record<string, Record<string, string>> | undefined,
  baseUrl: string | undefined,
): AddNewOption[] {
  const operations = createOperations?.[group.entityType] ?? {};
  const options: AddNewOption[] = [];
  for (const { bundle, label } of group.bundles) {
    if (!(bundle in operations)) {
      continue;
    }
    const url = buildEntityAddFormUrl(baseUrl, group.entityType, bundle);
    if (url === null) {
      continue;
    }
    options.push({ bundle, label, url });
  }
  return options;
}
