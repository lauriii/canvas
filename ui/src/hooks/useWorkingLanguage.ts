import { useCallback, useEffect } from 'react';
import {
  useHref,
  useLocation,
  useNavigate,
  useParams,
  useSearchParams,
} from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppSelector } from '@/app/hooks';
import { selectTranslations } from '@/features/layout/layoutModelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import {
  EDITED_TRANSLATION_PARAM,
  getEditorLanguage,
} from '@/services/baseQuery';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';
import { waitForPreviewRequests } from '@/services/preview';
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

// The state of the layout of a page in one of its languages.
// @see \Drupal\canvas\ContentTranslation\TranslationForkState
export type LayoutState =
  'symmetric' | 'forked' | 'pending_fork' | 'pending_unfork';

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

/**
 * The language the user selected last.
 */
export const getRememberedWorkingLanguage = () => {
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
    // A translation with its own layout, edited in the editor.
    findLanguage(searchParams.get(EDITED_TRANSLATION_PARAM)) ??
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
    // Before "Translated": a translation that has its own layout only in a
    // draft is not stored yet.
    if (hasOwnLayout(languageId)) {
      return 'Custom layout';
    }
    return translations.available.includes(languageId)
      ? 'Translated'
      : 'Not translated';
  };

  // The state of the layout of the open page in a language: whether the
  // language has its own layout (a forked translation), in its draft, in
  // storage, or both.
  // @see \Drupal\canvas\ContentTranslation\TranslationForkState
  const layoutStateOf = (languageId?: string): LayoutState | undefined =>
    languageId ? translations?.states?.[languageId] : undefined;

  // Whether the open page has its own layout in a language: it is edited in
  // the editor in that language. Draft-aware, like the server's access check.
  // @see \Drupal\canvas\Access\ForkedTranslationEditAccessCheck
  function hasOwnLayout(languageId?: string) {
    return (
      !!languageId &&
      !!(translations?.forked as string[] | undefined)?.includes(languageId)
    );
  }

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
  // A page with its own layout in the working language opens in the editor
  // in that language.
  const urlForPage = useCallback(
    (page: PageStub) =>
      isInOtherLanguage(page)
        ? `${urlForEditor('canvas_page', page.id)}?${page.translations?.[id!]?.forked ? EDITED_TRANSLATION_PARAM : 'language'}=${id}`
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
    layoutStateOf,
    hasOwnLayout,
    // Whether the open page was never published.
    isNew: isTemplateRoute ? undefined : (layout?.isNew as boolean | undefined),
    isMultilingual,
    isMissing,
    titleOf,
    pathOf,
    urlForPage,
  };
}

/**
 * The way to translate the open page or template into a language: the form of
 * a translation module. Not in the preview, which only shows a page.
 */
export function useTranslateAction() {
  const navigate = useNavigate();
  // Where Canvas is: a translation module can be a page extension in it.
  const canvasBase = useHref('/').replace(/\/$/, '');
  const { pathname } = useLocation();
  const { pageLanguage, translations, isNew, statusOf, layoutStateOf } =
    useWorkingLanguage();
  return (languageId?: string) => {
    const language = getLanguages().find(({ id }) => id === languageId);
    if (
      !language ||
      !pageLanguage ||
      language.id === pageLanguage ||
      pathname.startsWith('/preview/') ||
      // A page that was never published is not translated yet: all its
      // content is in its auto-save entry.
      isNew
    ) {
      return undefined;
    }
    // A translation module translates the text of a page that follows the
    // layout of the original: one with its own layout is edited in Canvas.
    // A pending return to the original layout is not published yet.
    if (
      statusOf(language.id) === 'Custom layout' ||
      layoutStateOf(language.id) === 'pending_unfork'
    ) {
      return undefined;
    }
    const links = translations?.links?.[language.id] ?? {};
    const url: string | undefined = links['edit-form'] ?? links['create'];
    if (!url) {
      return undefined;
    }
    // A page extension opens in Canvas, any other translation form in a new
    // tab.
    const inCanvasPath = url.startsWith(`${canvasBase}/`)
      ? url.slice(canvasBase.length)
      : undefined;
    return {
      label: `${statusOf(language.id) === 'Translated' ? 'Edit' : 'Add'} translation`,
      opensNewTab: !inCanvasPath,
      run: () => {
        if (inCanvasPath) {
          // The extension shows the language it translates into.
          rememberWorkingLanguage(language.id);
          navigate(inCanvasPath);
        } else {
          window.open(url, '_blank', 'noopener,noreferrer');
        }
      },
    };
  };
}

/**
 * Navigates within Canvas, loading the app afresh when the address names
 * another forked translation to edit than the current one.
 *
 * In-app navigation would keep the previous translation's layout in the
 * store while the address already names another translation, and the editor
 * could write it there before LayoutLoader reloads.
 *
 * @see LayoutLoader
 */
export function useNavigateToTranslation() {
  const navigate = useNavigate();
  const canvasBase = useHref('/').replace(/\/$/, '');
  return useCallback(
    (path: string, options?: { replace?: boolean }) => {
      if (
        getEditorLanguage(window.location.href) ===
        getEditorLanguage(`/canvas${path}`)
      ) {
        navigate(path, options);
        return;
      }
      const href = `${canvasBase}${path}`;
      // The last change of the layout may still be on its way.
      void waitForPreviewRequests().finally(() => {
        if (options?.replace) {
          window.location.replace(href);
        } else {
          window.location.assign(href);
        }
      });
    },
    [navigate, canvasBase],
  );
}
