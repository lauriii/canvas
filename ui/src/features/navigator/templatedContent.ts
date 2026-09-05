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

import { countExposedSlots } from '@/features/layout/exposedSlots';

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
      // "Active" means the enabled `full` template exposes slots: per-content
      // editing always resolves the full template (matching the server's
      // bundle gate), so slots exposed only in other view modes, or only in
      // disabled templates, must not enter the navigator.
      const fullViewMode = bundleData.viewModes?.full;
      const hasActiveExposedSlot =
        !!fullViewMode?.status &&
        countExposedSlots(fullViewMode.exposed_slots) > 0;
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

/**
 * Builds the URL of Drupal's own edit form for an entity.
 *
 * In per-content mode the contextual panel's Content tab links out to
 * Drupal's edit form for the entity's content fields (exposed-slots decision
 * 10, phase 1); rendering those widgets inline in the panel is phase 2. Only
 * `node` is supported, matching `buildEntityAddFormUrl` and the current
 * templated-entity scope; other entity types return null.
 *
 * @param baseUrl
 *   The Drupal base URL (`drupalSettings.path.baseUrl`, e.g. `/` or `/sub/`).
 * @param entityType
 *   The entity type machine name.
 * @param entityId
 *   The entity ID.
 *
 * @return
 *   The edit-form URL, or null when the entity type is unsupported.
 */
export function buildEntityEditFormUrl(
  baseUrl: string | undefined,
  entityType: string,
  entityId: string,
): string | null {
  if (entityType !== 'node') {
    return null;
  }
  const base = baseUrl && baseUrl.length > 0 ? baseUrl : '/';
  const normalized = base.endsWith('/') ? base : `${base}/`;
  return `${normalized}node/${entityId}/edit`;
}

/** A minimal templated content entity carrying the server's edit URLs. */
export interface ContentEntityEditTarget {
  id: string;
  // The entity's edit-form URL; the server omits it unless the user may update
  // the entity, so its presence is the permission gate.
  editUrl?: string | null;
  // The bundle's Field UI URL; omitted unless the user may administer fields.
  manageFieldsUrl?: string | null;
}

/** One permission-gated edit action for a templated content entity. */
export interface ContentEditAction {
  key: 'slots' | 'content' | 'fields';
  label: string;
  // External actions open a new tab (Drupal admin); internal ones navigate the
  // Canvas SPA.
  external: boolean;
  run: () => void;
}

/**
 * The edit actions available for a templated content entity, in menu order:
 * edit its exposed slots in Canvas, edit its content in the CMS, or manage the
 * bundle's fields. Each action is included only when its backing URL is present
 * (the server omits URLs the user has no access to), so gating is generic and
 * entity-type-agnostic. `navigateToEditor` is injected so this stays a pure,
 * hook-free builder usable inside list renders.
 */
export function buildContentEditActions(
  navigateToEditor: (entityType: string, entityId: string) => void,
  entityType: string | undefined,
  entity: ContentEntityEditTarget | undefined,
): ContentEditAction[] {
  if (!entityType || !entity) {
    return [];
  }
  const openExternal = (url: string) =>
    window.open(url, '_blank', 'noopener,noreferrer');
  const actions: ContentEditAction[] = [];
  if (entity.editUrl) {
    const editUrl = entity.editUrl;
    actions.push({
      key: 'slots',
      label: 'Edit exposed slots',
      external: false,
      run: () => navigateToEditor(entityType, entity.id),
    });
    actions.push({
      key: 'content',
      label: 'Edit content',
      external: true,
      run: () => openExternal(editUrl),
    });
  }
  if (entity.manageFieldsUrl) {
    const fieldsUrl = entity.manageFieldsUrl;
    actions.push({
      key: 'fields',
      label: 'Edit fields',
      external: true,
      run: () => openExternal(fieldsUrl),
    });
  }
  return actions;
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

/**
 * Every content type editable in Canvas that the current user may create, for
 * the Content panel's single "Add new" control next to the search field.
 *
 * Enumerates all of `contentEntityCreateOperations` (entity type -> bundle ->
 * label), which the server already scopes to Canvas-editable bundles the user
 * can create (create access + a Canvas field on the bundle) — so this is not
 * limited to bundles with an active exposed slot. Canvas pages are excluded
 * (they have their own creation in the Pages panel). Entries whose entity type
 * has no derivable add-form URL are dropped.
 *
 * @param createOperations
 *   `contentEntityCreateOperations`: entity type -> bundle -> label.
 * @param baseUrl
 *   The Drupal base URL.
 *
 * @return
 *   The creatable bundles, with labels and add-form URLs, one per bundle.
 */
export function getAllAddNewOptions(
  createOperations: Record<string, Record<string, string>> | undefined,
  baseUrl: string | undefined,
): AddNewOption[] {
  const options: AddNewOption[] = [];
  for (const [entityType, bundles] of Object.entries(createOperations ?? {})) {
    if (entityType === PAGE_ENTITY_TYPE) {
      continue;
    }
    for (const [bundle, label] of Object.entries(bundles)) {
      const url = buildEntityAddFormUrl(baseUrl, entityType, bundle);
      if (url === null) {
        continue;
      }
      options.push({ bundle, label, url });
    }
  }
  return options;
}
