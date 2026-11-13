/**
 * @file
 * The interface translation of generated previews, as interactive previews
 * look it up: for the components that the preview renders, in the language
 * that Drupal negotiates for the site data unless another is requested.
 */

import {
  aggregatePreviewTranslation,
  collectRenderedComponents,
  createTranslationLookup,
  fetchInterfaceLanguages,
  formatTranslationIssue,
  getInitialLangcode,
} from '../lib/preview-translation';
import { createAuthenticatedFetch } from './auth-proxy';
import { createComponentTranslationAnalysis } from './translation-metadata';

import type { Spec } from '@json-render/core';
import type {
  PreviewInterfaceTranslation,
  PreviewManifest,
} from '../lib/preview-contract';

export interface GeneratedPreviewTranslationInput {
  projectRoot: string;
  /** The entry files of the registered components. */
  registeredEntryPaths: string[];
  spec: Spec;
  /** The page template that renders the spec at its page content marker. */
  pageTemplateSpec?: Spec | null;
  componentSources: Array<{ name: string; jsEntryPath: string }>;
  /** The component that the registry renders for an element type. */
  resolveType: (type: string) => string | null;
}

export interface GeneratedPreviewTranslationResult {
  interfaceTranslation?: PreviewInterfaceTranslation;
  warnings: Array<{ code: string; message: string }>;
}

export type ResolveGeneratedPreviewTranslation = (
  input: GeneratedPreviewTranslationInput,
) => Promise<GeneratedPreviewTranslationResult>;

/**
 * Creates the resolver of the interface translation of generated previews.
 *
 * @param options.langcode
 *   The requested language, or `null` for the language that Drupal negotiates
 *   for the site data.
 * @param options.fetchImpl
 *   Fetches from the Drupal site, authenticated as the Workbench proxy.
 * @param options.fetchPublic
 *   Fetches public data from the Drupal site, without credentials, as
 *   Workbench fetches the site data.
 */
export function createGeneratedPreviewTranslationResolver(options: {
  langcode: string | null;
  fetchImpl: typeof fetch;
  fetchPublic: typeof fetch;
}): ResolveGeneratedPreviewTranslation {
  const lookup = createTranslationLookup(options.fetchImpl);
  let language: Promise<{ langcode: string } | { error: string }> | null = null;
  const getLanguage = () => {
    language ??= (async () => {
      const result = await fetchInterfaceLanguages(options.fetchImpl);
      if ('error' in result) {
        return result;
      }
      const { languages } = result;
      if (options.langcode) {
        return languages.languages.some(
          ({ langcode }) => langcode === options.langcode,
        )
          ? { langcode: options.langcode }
          : {
              error: `The language '${options.langcode}' is not an interface language of the Drupal site.`,
            };
      }
      let negotiated: string | undefined;
      try {
        const response = await options.fetchPublic('/canvas/api/v0/site-data', {
          headers: { Accept: 'application/json' },
        });
        negotiated = response.ok
          ? ((await response.json()) as { langcode?: string }).langcode
          : undefined;
      } catch {
        negotiated = undefined;
      }
      return { langcode: getInitialLangcode(languages, negotiated) };
    })();
    return language;
  };

  return async (input) => {
    const analyze = createComponentTranslationAnalysis({
      projectRoot: input.projectRoot,
      entryPaths: input.registeredEntryPaths,
    });
    const manifest = {
      components: input.componentSources.map(({ name, jsEntryPath }) => ({
        name,
        translation: analyze(jsEntryPath).translation,
      })),
    } as PreviewManifest;
    let pageContent = new Set<string>();
    const rendered = input.pageTemplateSpec
      ? collectRenderedComponents(
          input.pageTemplateSpec,
          input.resolveType,
          () => {
            pageContent = collectRenderedComponents(
              input.spec,
              input.resolveType,
            );
          },
        )
      : collectRenderedComponents(input.spec, input.resolveType);
    const translation = aggregatePreviewTranslation(
      [...rendered, ...pageContent],
      manifest,
    );
    if (translation.usesTranslationHook === false) {
      return { warnings: [] };
    }

    // The strings that cannot be translated, whatever the lookup.
    const warnings = translation.unknownComponents.map(({ name, issues }) => ({
      code: 'interface_translation_unknown_strings',
      message: `The strings of the component '${name}' are unknown, so they are not translated${issues.length > 0 ? `: ${issues.map(formatTranslationIssue).join(' ')}` : '.'}`,
    }));
    const selected = await getLanguage();
    if ('error' in selected) {
      return {
        warnings: [
          ...warnings,
          {
            code: 'interface_translation_unavailable',
            message: `${selected.error} The preview shows source strings.`,
          },
        ],
      };
    }
    const result = await lookup(
      selected.langcode,
      translation.translatableStrings,
      undefined,
    );
    return 'error' in result
      ? {
          interfaceTranslation: {
            langcode: selected.langcode,
            translations: null,
          },
          warnings: [
            ...warnings,
            {
              code: 'interface_translation_unavailable',
              message: `${result.error} The preview shows source strings.`,
            },
          ],
        }
      : {
          interfaceTranslation: {
            langcode: selected.langcode,
            translations: result.translations,
          },
          warnings,
        };
  };
}

/**
 * Creates the resolver for the Drupal site that the environment configures,
 * or `null` when none is configured.
 */
export async function createSiteGeneratedPreviewTranslationResolver(
  env: Record<string, string>,
  langcode: string | null,
): Promise<ResolveGeneratedPreviewTranslation | null> {
  const siteUrl = env.CANVAS_SITE_URL?.trim();
  if (!siteUrl) {
    return null;
  }
  return createGeneratedPreviewTranslationResolver({
    langcode,
    // Generated previews have no dev-server proxy: Workbench fetches the
    // translations itself, with the proxy's credentials.
    fetchImpl: await createAuthenticatedFetch(siteUrl, env),
    fetchPublic: (input, init) =>
      fetch(
        typeof input === 'string' || input instanceof URL
          ? new URL(input, siteUrl)
          : input,
        { ...init, credentials: 'omit' },
      ),
  });
}
