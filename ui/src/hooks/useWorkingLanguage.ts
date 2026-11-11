import { useCallback } from 'react';
import { useParams, useSearchParams } from 'react-router';

import { useAppSelector } from '@/app/hooks';
import { selectTranslations } from '@/features/layout/layoutModelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { getCanvasSettings, getLanguages } from '@/utils/drupal-globals';

import type { ContentStub } from '@/types/Content';
import type { Language } from '@/utils/drupal-globals';

type PageStub = Pick<
  ContentStub,
  'id' | 'title' | 'autoSaveLabel' | 'langcode' | 'translations'
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
 * language, a page in another language opens on the read-only preview route,
 * and the working language stays the same while moving between pages. Without
 * an open page, it is the language the user selected last.
 */
export function useWorkingLanguage() {
  const [searchParams] = useSearchParams();
  const { entityType } = useParams();
  const { urlForEditor } = useEditorNavigation();
  const translations = useAppSelector(selectTranslations);
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

  // The language the open page was created in. The translations in the store
  // are those of the last loaded layout, so they only say something on a
  // route that has an entity.
  const pageLanguage: string | undefined = entityType
    ? translations?.available?.[0]
    : undefined;
  // Always one of the site's languages: a page can be in a language that is
  // not, such as "Not specified".
  const workingLanguage =
    findLanguage(searchParams.get('language')) ??
    findLanguage(pageLanguage) ??
    (isMultilingual && !entityType
      ? findLanguage(getRememberedWorkingLanguage())
      : undefined) ??
    languages.find((language) => language.isDefault) ??
    languages[0];
  const id = workingLanguage?.id;

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
      (isInOtherLanguage(page) && page.translations?.[id!]) ||
      page.autoSaveLabel ||
      page.title,
    [isInOtherLanguage, id],
  );

  // A page in the working language opens in the editor. Any other page opens
  // read-only, in the working language when it is translated.
  const urlForPage = useCallback(
    (page: PageStub) =>
      isInOtherLanguage(page)
        ? `/preview/canvas_page/${page.id}/full?language=${id}`
        : urlForEditor('canvas_page', page.id),
    [isInOtherLanguage, id, urlForEditor],
  );

  return {
    workingLanguage,
    pageLanguage,
    isMultilingual,
    isMissing,
    titleOf,
    urlForPage,
  };
}
