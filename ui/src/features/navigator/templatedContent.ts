/**
 * Pure helpers for the in-editor content navigator's templated-entity groups.
 *
 * Bundles come from the content templates listing; entities from the
 * per-entity-type content list, which the server filters and access-checks.
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
 * `title` is the bundle label for a single bundle, else the entity type's
 * collection label ("Content", not the bundles' "Content types"): content list
 * items carry no bundle to group by.
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
    const title =
      bundles.length === 1 ? bundles[0].label : typeData.entityTypeLabel;
    groups.push({ entityType, title, bundles });
  }
  return groups;
}

/**
 * Builds the URL of Drupal's own creation form for a bundle.
 *
 * Only `node` is supported; other entity types return null.
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

/** A minimal templated content entity carrying the server's edit URLs. */
export interface ContentEntityEditTarget {
  id: string;
  // The entity's edit-form URL; the server omits it unless the user may update
  // the entity, so its presence is the permission gate.
  editUrl?: string | null;
  // The entity's Canvas editor URL; omitted unless the user may edit it in
  // Canvas (its bundle's `full` template exposes slots, like the "Edit in
  // Canvas" operation on the content overview).
  canvasUrl?: string | null;
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
 * The edit actions for a templated content entity. An action is included only
 * when the server sent its URL, which it does only when the user has access.
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
  if (entity.canvasUrl) {
    actions.push({
      key: 'slots',
      label: 'Edit in Canvas',
      external: false,
      run: () => navigateToEditor(entityType, entity.id),
    });
  }
  if (entity.editUrl) {
    const editUrl = entity.editUrl;
    actions.push({
      key: 'content',
      label: 'Edit CMS content',
      external: true,
      run: () => openExternal(editUrl),
    });
  }
  if (entity.manageFieldsUrl) {
    const fieldsUrl = entity.manageFieldsUrl;
    actions.push({
      key: 'fields',
      label: 'Manage fields',
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
 * Every content type editable in Canvas that the current user may create, for
 * the Content panel's single "Add new" control next to the search field.
 *
 * Excludes Canvas pages, which the Pages panel creates, and entity types
 * without an add-form URL.
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
