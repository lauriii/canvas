/**
 * Pure helpers for the in-editor content navigator's templated-entity groups.
 *
 * Beyond Canvas pages, the navigator lists entities of bundles with an enabled
 * full-view content template, grouped so they can be opened in the per-content
 * editor. Exposed slots are not required: zero-slot templated bundles open in
 * the editor with a locked canvas and an editable Content tab. The source of
 * truth for which bundles are templated is the content-templates listing
 * (`getContentTemplates`); the entities themselves come from the
 * per-entity-type content list endpoint (`/canvas/api/v0/content/{entityType}`,
 * which the server already access-filters to enabled-template bundles).
 *
 * These functions are side-effect free so the grouping, filtering, and
 * creation-option logic can be unit tested without a store or a backend.
 */

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
 * Excludes Canvas pages and any bundle without an enabled full-view template.
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
      // A bundle is editable when its `full` template is enabled: per-content
      // editing always resolves the full template (matching the server's
      // bundle gate). Exposed slots are not required — zero-slot bundles open
      // with a locked canvas and an editable Content tab — but templates for
      // other view modes, or disabled ones, must not enter the navigator.
      const fullViewMode = bundleData.viewModes?.full;
      if (fullViewMode?.status) {
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
 * Builds the URL of Drupal's own edit form for an entity.
 *
 * In per-content mode the contextual panel's Content tab links out to
 * Drupal's edit form for the entity's content fields (exposed-slots decision
 * 10, phase 1); rendering those widgets inline in the panel is phase 2. Only
 * `node` is supported, matching the current templated-entity scope; other
 * entity types return null.
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

/**
 * A creatable bundle: the coordinates for an in-canvas creation request
 * (`POST /canvas/api/v0/content/{entityType}` with `{bundle}`) and its label.
 */
export interface AddNewOption {
  entityType: string;
  bundle: string;
  label: string;
}

/**
 * The bundles in a group the current user may create.
 *
 * Gated by `drupalSettings.canvas.contentEntityCreateOperations`, which the
 * server computes from create access plus the presence of a Canvas field on the
 * bundle, so a bundle only becomes creatable once its template has provisioned
 * the field. Choosing an option creates an unpublished draft in Canvas
 * (`useCreateContentMutation`) and opens it in the editor; there is no
 * link-out to Drupal's add form.
 *
 * @param group
 *   The templated entity group.
 * @param createOperations
 *   `contentEntityCreateOperations`: entity type -> bundle -> label.
 *
 * @return
 *   The creatable bundles, with labels and creation coordinates.
 */
export function getAddNewOptions(
  group: TemplatedEntityGroup,
  createOperations: Record<string, Record<string, string>> | undefined,
): AddNewOption[] {
  const operations = createOperations?.[group.entityType] ?? {};
  const options: AddNewOption[] = [];
  for (const { bundle, label } of group.bundles) {
    if (!(bundle in operations)) {
      continue;
    }
    options.push({ entityType: group.entityType, bundle, label });
  }
  return options;
}

/**
 * Every content type editable in Canvas that the current user may create, for
 * the Content panel's single "Add new" control next to the search field.
 *
 * Enumerates all of `contentEntityCreateOperations` (entity type -> bundle ->
 * label), which the server already scopes to Canvas-editable bundles the user
 * can create (a Canvas field on the bundle, or an enabled full-view content
 * template — exposed slots not required). Canvas pages are excluded (they
 * have their own creation in the Pages panel). Choosing an option creates an
 * unpublished draft in Canvas and opens it in the editor.
 *
 * @param createOperations
 *   `contentEntityCreateOperations`: entity type -> bundle -> label.
 *
 * @return
 *   The creatable bundles, with labels and creation coordinates, one per
 *   bundle.
 */
export function getAllAddNewOptions(
  createOperations: Record<string, Record<string, string>> | undefined,
): AddNewOption[] {
  const options: AddNewOption[] = [];
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

/** One option in the top-bar navigation popover's content-type switcher. */
export interface ContentNavigationTypeOption {
  entityType: string;
  label: string;
}

/**
 * The content-type options for the top-bar navigation popover: Canvas pages
 * first, then one option per templated entity type. A multi-bundle entity type
 * still yields a single option because the per-entity-type content list API
 * already returns every editable bundle of the type.
 *
 * @param groups
 *   The templated entity groups (see `getTemplatedEntityGroups`).
 *
 * @return
 *   The switcher options, Pages first.
 */
export function getContentNavigationTypeOptions(
  groups: TemplatedEntityGroup[],
): ContentNavigationTypeOption[] {
  return [
    { entityType: PAGE_ENTITY_TYPE, label: 'Pages' },
    ...groups.map((group) => ({
      entityType: group.entityType,
      label: group.title,
    })),
  ];
}

/**
 * Resolves the selected content type against the available options, falling
 * back to Canvas pages. Guards the switcher while the templates query is still
 * loading and against a stale selection (e.g. a template disabled meanwhile).
 *
 * @param selected
 *   The entity type the user picked.
 * @param options
 *   The available switcher options.
 *
 * @return
 *   The selected entity type when it is available, `canvas_page` otherwise.
 */
export function resolveContentNavigationType(
  selected: string,
  options: ContentNavigationTypeOption[],
): string {
  return options.some((option) => option.entityType === selected)
    ? selected
    : PAGE_ENTITY_TYPE;
}
