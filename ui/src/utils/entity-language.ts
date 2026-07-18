import { getCanvasSettings, getLanguages } from '@/utils/drupal-globals';

/**
 * Registry of each entity's original (default) language.
 *
 * Editor API requests must upcast the entity's original translation, not the
 * translation the site's language negotiation would pick. When the original
 * language differs from the site default, requests carry its URL language
 * prefix. The registry is seeded at boot from
 * `drupalSettings.canvas.entityDefaultLangcode` and updated from every layout
 * response's `translations.defaultLangcode`, so it stays correct across
 * client-side navigation between entities.
 *
 * @see \Drupal\canvas\Controller\CanvasController
 * @see ui/src/services/baseQuery.ts
 */

const registry = new Map<string, string>();
const listeners = new Set<() => void>();
let seeded = false;

const key = (entityType: string, entityId: string) =>
  `${entityType}:${entityId}`;

/** Seeds the registry with the boot entity's original language. */
const seedFromBoot = () => {
  if (seeded) {
    return;
  }
  seeded = true;
  const bootLangcode = getCanvasSettings()?.entityDefaultLangcode;
  if (!bootLangcode) {
    return;
  }
  // The boot URL names the boot entity: /canvas/editor/:type/:id or
  // /canvas/preview/:type/:id.
  const match = window.location.pathname.match(
    /\/canvas\/(?:editor|preview)\/([^/]+)\/([^/]+)/,
  );
  if (match) {
    registry.set(key(match[1], match[2]), bootLangcode);
  }
};

export const recordEntityDefaultLangcode = (
  entityType: string,
  entityId: string,
  langcode: string,
) => {
  seedFromBoot();
  const k = key(entityType, entityId);
  if (registry.get(k) === langcode) {
    return;
  }
  registry.set(k, langcode);
  listeners.forEach((listener) => listener());
};

export const getEntityDefaultLangcode = (
  entityType?: string,
  entityId?: string,
): string | undefined => {
  seedFromBoot();
  if (!entityType || !entityId) {
    return undefined;
  }
  return registry.get(key(entityType, entityId));
};

/** For reactive consumers (useSyncExternalStore). */
export const subscribeToEntityLanguages = (listener: () => void) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};

/**
 * The URL language prefix for an entity's original language.
 *
 * Empty when the original language is unknown, is the site default, or has no
 * configured URL prefix (in which case a prefixed URL would not resolve).
 */
export const getEntityLanguagePrefix = (
  entityType?: string,
  entityId?: string,
): string => {
  const langcode = getEntityDefaultLangcode(entityType, entityId);
  if (!langcode) {
    return '';
  }
  const language = getLanguages().find((lang) => lang.id === langcode);
  if (!language || language.isDefault || !language.urlPrefix) {
    return '';
  }
  return language.urlPrefix;
};

// The entity-scoped editor API endpoints whose entity upcast must pin to the
// original language. Matched against client-constructed relative URLs only;
// server-generated links (absolute, base-path-qualified, already carrying
// their own language prefix when needed) never match the anchors.
const ENTITY_SCOPED_API_PATTERNS = [
  /^\/?canvas\/api\/v0\/layout\/([^/]+)\/([^/]+)$/,
  /^\/?canvas\/api\/v0\/content\/auto-save\/([^/]+)\/([^/]+)$/,
  /^\/?canvas\/api\/v0\/content\/([^/]+)\/([^/]+)$/,
  /^\/?canvas\/api\/v0\/form\/content-entity\/([^/]+)\/([^/]+)\/[^/]+$/,
  /^\/?canvas\/api\/v0\/form\/component-instance\/([^/]+)\/([^/]+)$/,
];

/**
 * Prefixes an editor API URL with the target entity's original language.
 *
 * A missed construction site would produce auto-save entries keyed to the
 * wrong langcode, so this applies centrally in the base query to every
 * entity-scoped editor endpoint. URLs for entities whose original language is
 * unknown, the site default, or without a URL prefix pass through unchanged.
 */
export const applyEntityLanguagePrefix = (url: string): string => {
  const [path, ...rest] = url.split(/(?=[?#])/);
  for (const pattern of ENTITY_SCOPED_API_PATTERNS) {
    const match = path.match(pattern);
    if (!match) {
      continue;
    }
    const prefix = getEntityLanguagePrefix(match[1], match[2]);
    if (!prefix) {
      return url;
    }
    const prefixedPath = path.startsWith('/')
      ? `/${prefix}${path}`
      : `${prefix}/${path}`;
    return `${prefixedPath}${rest.join('')}`;
  }
  return url;
};
