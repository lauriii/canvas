/**
 * @file
 * The interface translation of what a Workbench preview renders: the
 * components that its specs render, whether they translate interface text,
 * and the translations of their strings, looked up in Drupal.
 */

import type { Spec } from '@json-render/core';
import type { Translations } from 'drupal-canvas';
import type {
  PreviewManifest,
  PreviewManifestComponentTranslation,
  PreviewRenderRequest,
  PreviewTranslationIssue,
} from './preview-contract';

type TranslatableString = NonNullable<
  PreviewManifestComponentTranslation['translatableStrings']
>[number];

const COMPONENT_TREE = 'canvas:component-tree';
const PAGE_CONTENT_MARKER = 'marker.page_content';

/**
 * The names of the components that a spec renders, as `renderSpec()` renders
 * them.
 *
 * Follows the root, children and named slots. The synthetic
 * `canvas:component-tree` wrapper renders its children only, and an element
 * whose type the registry does not have renders nothing, like its
 * descendants. Specs whose elements form cycles are followed once.
 *
 * @param spec
 *   The spec.
 * @param resolve
 *   Returns the name of the component that the registry renders for an
 *   element type, or `null` when it renders none.
 * @param onPageContent
 *   Called when the spec renders the page content marker of a page template.
 */
export function collectRenderedComponents(
  spec: Spec,
  resolve: (type: string) => string | null,
  onPageContent?: () => void,
): Set<string> {
  const components = new Set<string>();
  const visited = new Set<string>();
  const visit = (key: string): void => {
    const element = spec.elements[key];
    if (!element || visited.has(key)) {
      return;
    }
    visited.add(key);
    if (element.type === COMPONENT_TREE) {
      for (const child of element.children ?? []) {
        visit(child);
      }
      return;
    }
    if (element.type === PAGE_CONTENT_MARKER && onPageContent) {
      onPageContent();
      return;
    }
    const name = resolve(element.type);
    if (name === null) {
      return;
    }
    components.add(name);
    for (const child of element.children ?? []) {
      visit(child);
    }
    const slots = (element as { slots?: Record<string, string[]> }).slots;
    for (const children of Object.values(slots ?? {})) {
      for (const child of children) {
        visit(child);
      }
    }
  };
  visit(spec.root);
  return components;
}

/**
 * Resolves element types to component names, as the preview frame's registry
 * registers components: by name, and by `js.` and their lowercased name. A
 * later component replaces an earlier one with the same name.
 */
export function createRegistryResolver(
  registrySources: PreviewRenderRequest['payload']['registrySources'],
): (type: string) => string | null {
  const names = new Map<string, string>();
  for (const { name } of registrySources) {
    for (const alias of name.startsWith('js.')
      ? [name]
      : [name, `js.${name.toLowerCase()}`]) {
      names.set(alias, name);
    }
  }
  return (type) => names.get(type) ?? null;
}

/**
 * The names of the components that a render request renders.
 *
 * A page template renders the page content only where it renders its marker.
 */
export function collectRequestComponents(
  payload: PreviewRenderRequest['payload'],
): Set<string> {
  const resolve = createRegistryResolver(payload.registrySources);
  if (payload.renderType === 'page' && payload.pageTemplate) {
    let pageContent = new Set<string>();
    const components = collectRenderedComponents(
      payload.pageTemplate.spec,
      resolve,
      () => {
        pageContent = collectRenderedComponents(payload.spec, resolve);
      },
    );
    return new Set([...components, ...pageContent]);
  }
  return collectRenderedComponents(payload.spec, resolve);
}

export interface PreviewTranslation {
  /**
   * Whether a rendered component refers to `useTranslation()`, or `null` when
   * that is unknown for one that does not.
   */
  usesTranslationHook: boolean | null;
  /** The known strings of the rendered components, deduplicated. */
  translatableStrings: TranslatableString[];
  /** The rendered components whose strings are unknown, and why. */
  unknownComponents: Array<{
    name: string;
    issues: PreviewTranslationIssue[];
  }>;
}

/**
 * Aggregates the interface translation of the rendered components.
 */
export function aggregatePreviewTranslation(
  components: Iterable<string>,
  manifest: PreviewManifest,
): PreviewTranslation {
  const byName = new Map(
    manifest.components.map((component) => [component.name, component]),
  );
  let usesHook = false;
  let unknown = false;
  const strings = new Map<string, TranslatableString>();
  const unknownComponents: PreviewTranslation['unknownComponents'] = [];
  for (const name of components) {
    const translation = byName.get(name)?.translation;
    if (translation?.usesTranslationHook) {
      usesHook = true;
    } else if (!translation || translation.usesTranslationHook === null) {
      unknown = true;
    }
    if (!translation || translation.translatableStrings === null) {
      unknownComponents.push({ name, issues: translation?.issues ?? [] });
    }
    for (const string of translation?.translatableStrings ?? []) {
      strings.set(
        JSON.stringify([string.context ?? '', string.source, string.plural]),
        string,
      );
    }
  }
  // A known use of the hook is known, whatever the other components.
  const usesTranslationHook = usesHook || (unknown ? null : false);
  return {
    usesTranslationHook,
    translatableStrings: [...strings.values()],
    unknownComponents,
  };
}

export interface InterfaceLanguage {
  langcode: string;
  label: string;
  direction: 'ltr' | 'rtl';
}

export interface InterfaceLanguages {
  defaultLangcode: string;
  languages: InterfaceLanguage[];
}

const INTERFACE_TRANSLATION_PATH = '/canvas/api/v0/interface-translation';

export type TranslationLookupResult =
  { translations: Translations } | { error: string };

/**
 * Fetches the languages that Drupal translates interface text into, through
 * the Workbench proxy.
 */
export async function fetchInterfaceLanguages(
  fetchImpl: typeof fetch = fetch,
): Promise<{ languages: InterfaceLanguages } | { error: string }> {
  try {
    const response = await fetchImpl(INTERFACE_TRANSLATION_PATH, {
      headers: { Accept: 'application/json' },
    });
    return response.ok
      ? { languages: (await response.json()) as InterfaceLanguages }
      : { error: describeFailure('the interface languages', response) };
  } catch (error) {
    return {
      error: `Failed to fetch the interface languages: ${error instanceof Error ? error.message : String(error)}`,
    };
  }
}

function describeFailure(subject: string, response: Response): string {
  return `Failed to fetch ${subject} from Drupal: ${response.status}${response.status === 401 || response.status === 403 ? ' (check the credentials of Workbench)' : ''}.`;
}

/**
 * The language that previews show first: the language selected earlier, then
 * the interface language that Drupal negotiated for the site data, then the
 * default language.
 *
 * @param storedLangcode
 *   The language selected earlier, which is ignored unless the site still
 *   translates interface text into it.
 */
export function getInitialLangcode(
  languages: InterfaceLanguages,
  negotiatedLangcode: string | undefined,
  storedLangcode: string | null = null,
): string {
  const available = new Set(
    languages.languages.map(({ langcode }) => langcode),
  );
  return (
    [storedLangcode, negotiatedLangcode].find(
      (candidate): candidate is string =>
        !!candidate && available.has(candidate),
    ) ?? languages.defaultLangcode
  );
}

const PREVIEW_LANGCODE_STORAGE_KEY = 'canvas-workbench:preview-langcode:';

/**
 * The storage of the selected language: the browser tab's session storage.
 *
 * @returns
 *   The storage, or `null` when there is none, such as on the server or when
 *   the browser denies access to it.
 */
function getSessionStorage(): Storage | null {
  try {
    return typeof window === 'undefined' ? null : window.sessionStorage;
  } catch {
    return null;
  }
}

/**
 * The key that stores the selected language for a Drupal site.
 *
 * Distinct sites that one Workbench serves in turn have distinct keys. The key
 * leaves out the credentials, query and fragment of the site URL.
 *
 * @returns
 *   The key, or `null` for an invalid site URL.
 */
export function getPreviewLangcodeStorageKey(siteUrl: string): string | null {
  try {
    const url = new URL(siteUrl);
    return `${PREVIEW_LANGCODE_STORAGE_KEY}${url.origin}${url.pathname.replace(/\/+$/, '')}`;
  } catch {
    return null;
  }
}

/**
 * Reads the language selected earlier in the browser tab for a Drupal site.
 */
export function readPreviewLangcode(
  siteUrl: string,
  storage: Storage | null = getSessionStorage(),
): string | null {
  const key = getPreviewLangcodeStorageKey(siteUrl);
  if (!storage || !key) {
    return null;
  }
  try {
    return storage.getItem(key);
  } catch {
    return null;
  }
}

/**
 * Stores the language selected in the browser tab for a Drupal site.
 *
 * Without storage, the selection lasts until the page reloads.
 */
export function storePreviewLangcode(
  siteUrl: string,
  langcode: string,
  storage: Storage | null = getSessionStorage(),
): void {
  const key = getPreviewLangcodeStorageKey(siteUrl);
  if (!storage || !key) {
    return;
  }
  try {
    storage.setItem(key, langcode);
  } catch {
    // Such as when the storage is full.
  }
}

/**
 * Loads the interface languages of a Drupal site, and the language that
 * previews show first.
 *
 * @returns
 *   The languages and the language, why they are not available, or `null`
 *   when the site changed meanwhile.
 */
export async function loadPreviewLanguages(options: {
  siteUrl: string;
  negotiatedLangcode: string | undefined;
  isCurrent: () => boolean;
  fetchImpl?: typeof fetch;
  storage?: Storage | null;
}): Promise<
  { languages: InterfaceLanguages; langcode: string } | { error: string } | null
> {
  const result = await fetchInterfaceLanguages(options.fetchImpl);
  if (!options.isCurrent()) {
    return null;
  }
  if ('error' in result) {
    return result;
  }
  return {
    languages: result.languages,
    langcode: getInitialLangcode(
      result.languages,
      options.negotiatedLangcode,
      readPreviewLangcode(
        options.siteUrl,
        options.storage === undefined ? getSessionStorage() : options.storage,
      ),
    ),
  };
}

/**
 * Creates a lookup of the translations of strings, cached by language, the
 * strings and the revision of their metadata.
 *
 * @param fetchImpl
 *   Fetches from the Drupal site.
 * @param options.getCsrfToken
 *   Gets the CSRF token of the browser's Drupal session, for lookups that the
 *   browser sends with its cookies: Drupal requires the token of a request
 *   with a session cookie, also when the Workbench proxy authenticates it.
 *
 * @returns
 *   Looks up the translations, or why they are not available.
 */
export function createTranslationLookup(
  fetchImpl: typeof fetch = fetch,
  options: { getCsrfToken?: () => Promise<string | null> } = {},
): (
  langcode: string,
  strings: TranslatableString[],
  revision: number | undefined,
) => Promise<TranslationLookupResult> {
  const cache = new Map<string, Promise<TranslationLookupResult>>();
  return (langcode, strings, revision) => {
    const key = JSON.stringify([langcode, revision ?? null, strings]);
    let lookup = cache.get(key);
    if (!lookup) {
      lookup = (async () => {
        if (strings.length === 0) {
          return { translations: { strings: {} } };
        }
        try {
          const headers: Record<string, string> = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
          };
          if (options.getCsrfToken) {
            const csrfToken = await options.getCsrfToken();
            if (csrfToken) {
              headers['X-CSRF-Token'] = csrfToken;
            }
          }
          const response = await fetchImpl(
            `${INTERFACE_TRANSLATION_PATH}/${encodeURIComponent(langcode)}`,
            {
              method: 'POST',
              ...(options.getCsrfToken ? { credentials: 'include' } : {}),
              headers,
              body: JSON.stringify({ strings }),
            },
          );
          return response.ok
            ? { translations: (await response.json()) as Translations }
            : {
                error: describeFailure(
                  `the translations into '${langcode}'`,
                  response,
                ),
              };
        } catch (error) {
          return {
            error: `Failed to fetch the translations into '${langcode}': ${error instanceof Error ? error.message : String(error)}`,
          };
        }
      })();
      cache.set(key, lookup);
      // Failed lookups are tried again.
      void lookup.then((result) => {
        if ('error' in result) {
          cache.delete(key);
        }
      });
    }
    return lookup;
  };
}

/**
 * Formats an issue that makes the strings of a component unknown.
 */
export function formatTranslationIssue(issue: PreviewTranslationIssue): string {
  const location = [issue.path, issue.line, issue.column]
    .filter((part) => part !== undefined)
    .join(':');
  return `${location}: ${issue.message}`;
}

/**
 * Sends a render request with the interface translation of what it renders.
 *
 * Nothing is sent, nor reported, when a later render replaced the request
 * meanwhile, such as after selecting another language, mock or target.
 */
export async function sendTranslatedRenderRequest(options: {
  message: PreviewRenderRequest;
  manifest: PreviewManifest;
  /** The language to translate into, or `null` when there is none. */
  langcode: string | null;
  lookup: ReturnType<typeof createTranslationLookup>;
  isCurrent: () => boolean;
  post: (message: PreviewRenderRequest) => void;
  /** Reports what the preview renders, and why a lookup failed. */
  onTranslation: (
    translation: PreviewTranslation,
    lookupError: string | null,
  ) => void;
}): Promise<void> {
  if (!options.isCurrent()) {
    return;
  }
  const translation = aggregatePreviewTranslation(
    collectRequestComponents(options.message.payload),
    options.manifest,
  );
  if (!options.langcode || translation.usesTranslationHook === false) {
    options.onTranslation(translation, null);
    options.post(options.message);
    return;
  }
  const result = await options.lookup(
    options.langcode,
    translation.translatableStrings,
    options.manifest.translationRevision,
  );
  if (!options.isCurrent()) {
    return;
  }
  options.onTranslation(translation, 'error' in result ? result.error : null);
  options.post({
    ...options.message,
    payload: {
      ...options.message.payload,
      interfaceTranslation: {
        langcode: options.langcode,
        translations: 'error' in result ? null : result.translations,
      },
    },
  });
}
