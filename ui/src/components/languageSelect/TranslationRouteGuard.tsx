import { useEffect } from 'react';
import { useLocation, useParams, useSearchParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import {
  useNavigateToTranslation,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import { EDITED_TRANSLATION_PARAM } from '@/services/baseQuery';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';

/**
 * Keeps the editor route in step with the layout of the language it names.
 *
 * A language in which the page has a custom layout (a forked translation) is
 * edited in the editor, with `?translation=`. Any other language is shown,
 * with `?language=`, and edited with a translation module. The state can
 * change after a link was made: the route follows the state the page loads
 * with, and the changes made in this tab.
 */
const TranslationRouteGuard = () => {
  const [searchParams] = useSearchParams();
  const { entityType, entityId } = useParams();
  const { pathname, search } = useLocation();
  const shown = searchParams.get('language');
  const edited = searchParams.get(EDITED_TRANSLATION_PARAM);
  const { hasOwnLayout } = useWorkingLanguage();
  const navigateToTranslation = useNavigateToTranslation();
  // The editor's own request for a translation that does not have its own
  // layout is refused: read the state from one that shows the translation,
  // which is allowed for every translation.
  const { currentData: editedLayout } = useGetPageLayoutQuery(
    edited && entityType && entityId
      ? { entityId, entityType, language: edited }
      : skipToken,
  );
  const editedTranslations = editedLayout?.translations;
  const isShownEditable = !!shown && hasOwnLayout(shown);

  useEffect(() => {
    if (!entityType || !entityId) {
      return;
    }
    // The same address, e.g. with the selected component, in another way.
    const addressFor = (param?: string, langcode?: string) => {
      const params = new URLSearchParams(search);
      params.delete('language');
      params.delete(EDITED_TRANSLATION_PARAM);
      if (param && langcode) {
        params.set(param, langcode);
      }
      const query = params.toString();
      return `${pathname}${query ? `?${query}` : ''}`;
    };
    if (isShownEditable) {
      navigateToTranslation(addressFor(EDITED_TRANSLATION_PARAM, shown!), {
        replace: true,
      });
      return;
    }
    if (
      edited &&
      editedTranslations?.available &&
      !editedTranslations.forked?.includes(edited)
    ) {
      navigateToTranslation(
        edited === editedTranslations.available[0]
          ? addressFor()
          : addressFor('language', edited),
        { replace: true },
      );
    }
  }, [
    entityType,
    entityId,
    pathname,
    search,
    shown,
    edited,
    isShownEditable,
    editedTranslations,
    navigateToTranslation,
  ]);

  return null;
};

export default TranslationRouteGuard;
