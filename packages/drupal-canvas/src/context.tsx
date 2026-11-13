/**
 * @file
 * The shared page and site context for React Code Components. Rendering
 * integrations (Drupal islands, the headless React renderer, Workbench
 * previews) establish the provider; components read it with the hooks.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

import { createContext, useContext, useEffect, useMemo } from 'react';

import { reportCanvasData } from './data-inspection.js';
import { formatPlural, translate } from './translation.js';
import { warnOnce } from './warnings.js';

import type { ReactNode } from 'react';
import type {
  CanvasContext,
  PageContext,
  SiteContext,
  Translations,
} from './context-types.js';
import type { FormatPluralFunction, TranslateFunction } from './translation.js';

export type {
  CanvasContext,
  PageContext,
  SiteContext,
  Translations,
} from './context-types.js';

export interface CanvasContextProviderProps {
  context: CanvasContext;
  children?: ReactNode;
}

/**
 * `undefined` means no provider is mounted; an explicit `null` page or site
 * value inside a provider means the integration has no such data.
 */
const Context = createContext<CanvasContext | undefined>(undefined);
Context.displayName = 'CanvasContext';

/**
 * Makes page and site context available to every React descendant. Adds no
 * DOM wrapper. Rendering integrations use it internally; applications can use
 * it to reach components outside the Canvas tree, such as a site header.
 */
export function CanvasContextProvider({
  context,
  children,
}: CanvasContextProviderProps) {
  return <Context.Provider value={context}>{children}</Context.Provider>;
}

export interface TranslationsProviderProps {
  /**
   * The translations for every component below, e.g. the application's own
   * dictionary. They replace the translations of the Canvas context instead
   * of being merged with them; `null` means none, so components show their
   * source strings.
   */
  translations: Translations | null;
  children?: ReactNode;
}

/** `undefined` means no `TranslationsProvider` is mounted. */
const TranslationsContext = createContext<Translations | null | undefined>(
  undefined,
);
TranslationsContext.displayName = 'CanvasTranslations';

/**
 * Replaces the translations `useTranslation()` uses below it. The nearest
 * provider takes precedence over the Canvas context's translations, also over
 * a context that a rendering integration provides further down, such as the
 * `context` of `CanvasComponentTree`. Without it, components use the
 * translations of the Canvas context. Adds no DOM wrapper.
 */
export function TranslationsProvider({
  translations,
  children,
}: TranslationsProviderProps) {
  return (
    <TranslationsContext.Provider value={translations}>
      {children}
    </TranslationsContext.Provider>
  );
}

const MISSING_PROVIDER_WARNING =
  'No CanvasContextProvider is mounted above this component. In headless ' +
  'React applications pass `context={page.context}` to CanvasComponentTree ' +
  'or wrap the tree in CanvasContextProvider; in Drupal and Workbench the ' +
  'rendering integration provides it.';

/**
 * Returns the current page context, or `null` when no provider supplies one.
 * Emits a deduplicated developer warning in that case; a provider that
 * carries valid empty page data is not a missing-context condition.
 */
export function usePageContext(): PageContext | null {
  const context = useContext(Context);
  const page = context === undefined ? null : context.page;
  if (context === undefined) {
    warnOnce(`usePageContext(): ${MISSING_PROVIDER_WARNING}`);
  } else if (page === null) {
    warnOnce(
      'usePageContext(): the rendering integration supplied no page context ' +
        '(`context.page` is null), so the hook returns null.',
    );
  }
  useEffect(() => {
    if (context !== undefined) {
      reportCanvasData('usePageContext()', page);
    }
  }, [context, page]);
  return page;
}

/**
 * Returns the current site context, or `null` when no provider supplies one.
 * Emits a deduplicated developer warning in that case.
 */
export function useSiteContext(): SiteContext | null {
  const context = useContext(Context);
  const site = context === undefined ? null : context.site;
  if (context === undefined) {
    warnOnce(`useSiteContext(): ${MISSING_PROVIDER_WARNING}`);
  } else if (site === null) {
    warnOnce(
      'useSiteContext(): the rendering integration supplied no site context ' +
        '(`context.site` is null), so the hook returns null.',
    );
  }
  useEffect(() => {
    if (context !== undefined) {
      reportCanvasData('useSiteContext()', site);
    }
  }, [context, site]);
  return site;
}

/**
 * Returns `t()` and `formatPlural()`, which translate strings into the page's
 * interface language. They take the arguments of `Drupal.t()` and
 * `Drupal.formatPlural()`, with `@` placeholders only, but return text, not
 * HTML, which React escapes when it renders it. The code editor, the Canvas
 * CLI, and the Headless SDK find the strings to translate in the component
 * source: pass string literals.
 *
 * The translations come from the nearest `TranslationsProvider`, otherwise
 * from the Canvas context. Without a translation for a string, they return the
 * source string. Without either provider they also warn once.
 *
 * @example
 * const { t, formatPlural } = useTranslation();
 * t('Hello @name', { '@name': name });
 * formatPlural(count, '1 item', '@count items');
 */
export function useTranslation(): {
  t: TranslateFunction;
  formatPlural: FormatPluralFunction;
} {
  const context = useContext(Context);
  const override = useContext(TranslationsContext);
  if (context === undefined && override === undefined) {
    warnOnce(`useTranslation(): ${MISSING_PROVIDER_WARNING}`);
  }
  const translations =
    override === undefined ? context?.translations : override;
  return useMemo(
    () => ({
      t: (str, args, options) => translate(translations, str, args, options),
      formatPlural: (count, singular, plural, args, options) =>
        formatPlural(translations, count, singular, plural, args, options),
    }),
    [translations],
  );
}

/**
 * Whether a `CanvasContextProvider` is mounted above the calling component.
 * Rendering integrations use it to decide whether to inherit an outer
 * provider instead of adding their own.
 */
export function useHasCanvasContext(): boolean {
  return useContext(Context) !== undefined;
}
