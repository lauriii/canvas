import { useCallback, useEffect } from 'react';
import { useLocation, useParams, useSearchParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppSelector } from '@/app/hooks';
import { selectTranslations } from '@/features/layout/layoutModelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';
import { getCanvasSettings, getLanguages } from '@/utils/drupal-globals';

import type { ContentStub } from '@/types/Content';
import type { Language } from '@/utils/drupal-globals';

type PageStub = Pick<
  ContentStub,
  | 'id'
  | 'title'
  | 'autoSaveLabel'
  | 'path'
  | 'autoSavePath'
  | 'langcode'
  | 'translations'
>;

const STORAGE_KEY = 'Canvas.workingLanguage';

/**
 * Remembers the language the user selected, for the next visit to Canvas.
 */
export const rememberWorkingLanguage = (id: string) => {
  try {
    window.localStorage.setItem(STORAGE_KEY, id);
  } catch {
    // Storage can be unavailable; the working language then is not remembered.
  }
};

// The working language last known while a page or template was open. It
// stands in while the language of the next page loads, so the page lists do
// not switch to another language and back.
let lastWorkingLanguage: string | null = null;

const getRememberedWorkingLanguage = () => {
  try {
    return window.localStorage.getItem(STORAGE_KEY);
  } catch {
    return null;
  }
};

/**
 * The working language: the language Canvas shows pages in, and creates new
 * pages in.
 *
 * It is the `language` query parameter when there is one, and the language of
 * the open page otherwise. So the editor route always edits a page in its own
 * language, shows a page in another language in the working language, and the
 * working language stays the same while moving between pages. Without
 * an open page, it is the language the user selected last.
 */
export function useWorkingLanguage() {
  const [searchParams] = useSearchParams();
  const { pathname } = useLocation();
  const { entityType, entityId } = useParams();
  const { urlForEditor } = useEditorNavigation();
  const storeTranslations = useAppSelector(selectTranslations);
  const languages = getLanguages();
  const settings = getCanvasSettings();
  // The same condition as for showing the language selector in the top bar.
  const isMultilingual =
    languages.length > 1 &&
    Boolean(
      settings?.contentTranslationEnabled || settings?.configTranslationEnabled,
    );
  const findLanguage = (id?: string | null): Language | undefined =>
    languages.find((language) => language.id === id);

  // The route of a template names the entity it previews, not the template:
  // its translations are those the template editor or preview stored.
  const isTemplateRoute =
    pathname.startsWith('/template/') ||
    pathname.startsWith('/preview/template/');
  // The layout of the open page: the same query the editor and the preview
  // make, so no request of its own. The translations in the store can still
  // be those of the page opened before.
  const { currentData: layout } = useGetPageLayoutQuery(
    entityType && entityId && !isTemplateRoute
      ? { entityId, entityType }
      : skipToken,
  );
  const translations: Record<string, any> | undefined = isTemplateRoute
    ? entityType
      ? storeTranslations
      : undefined
    : layout?.translations;
  // The language the open page or template was created in.
  const pageLanguage: string | undefined = translations?.available?.[0];
  // A page is open, but its language has not loaded yet.
  const isPageLanguageLoading =
    !!entityType && !!entityId && !isTemplateRoute && !layout;

  // Always one of the site's languages: a page can be in a language that is
  // not, such as "Not specified".
  const workingLanguage =
    findLanguage(searchParams.get('language')) ??
    findLanguage(pageLanguage) ??
    (isPageLanguageLoading ? findLanguage(lastWorkingLanguage) : undefined) ??
    (isMultilingual && !entityType
      ? findLanguage(getRememberedWorkingLanguage())
      : undefined) ??
    languages.find((language) => language.isDefault) ??
    languages[0];
  const id = workingLanguage?.id;
  // The working language is a guess until the language of the open page has
  // loaded, unless an earlier page made it known.
  const isResolved =
    !isPageLanguageLoading ||
    searchParams.has('language') ||
    !!findLanguage(lastWorkingLanguage);

  useEffect(() => {
    if (id && !isPageLanguageLoading) {
      lastWorkingLanguage = id;
    }
  }, [id, isPageLanguageLoading]);

  // How the open page or template relates to a language.
  const statusOf = (languageId?: string) => {
    if (!entityType || !languageId || !translations?.available) {
      return null;
    }
    if (languageId === pageLanguage) {
      return 'Original';
    }
    // The translations of a page variant are not reported.
    if (entityType === PAGE_VARIANT_ENTITY_TYPE) {
      return null;
    }
    return translations.available.includes(languageId)
      ? 'Translated'
      : 'Not translated';
  };

  // Whether the page was created in another language of the site.
  const isInOtherLanguage = useCallback(
    (page: PageStub) =>
      isMultilingual &&
      !!id &&
      !!page.langcode &&
      page.langcode !== id &&
      getLanguages().some((language) => language.id === page.langcode),
    [isMultilingual, id],
  );

  // Whether the page has no version in the working language.
  const isMissing = useCallback(
    (page: PageStub) =>
      isInOtherLanguage(page) && !(id! in (page.translations ?? {})),
    [isInOtherLanguage, id],
  );

  const titleOf = useCallback(
    (page: PageStub) =>
      (isInOtherLanguage(page) && page.translations?.[id!]?.title) ||
      page.autoSaveLabel ||
      page.title,
    [isInOtherLanguage, id],
  );

  // The path of the page in the working language, with its language prefix.
  const pathOf = useCallback(
    (page: PageStub) =>
      (isInOtherLanguage(page) && page.translations?.[id!]?.path) ||
      page.autoSavePath ||
      page.path,
    [isInOtherLanguage, id],
  );

  // A page in the working language opens in the editor. Any other page opens
  // read-only, in the working language when it is translated.
  const urlForPage = useCallback(
    (page: PageStub) =>
      isInOtherLanguage(page)
        ? `${urlForEditor('canvas_page', page.id)}?language=${id}`
        : urlForEditor('canvas_page', page.id),
    [isInOtherLanguage, id, urlForEditor],
  );

  return {
    workingLanguage,
    isResolved,
    pageLanguage,
    // The translations of the open page or template.
    translations,
    statusOf,
    // Whether the open page was never published.
    isNew: isTemplateRoute ? undefined : (layout?.isNew as boolean | undefined),
    isMultilingual,
    isMissing,
    titleOf,
    pathOf,
    urlForPage,
  };
}
