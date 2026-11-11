import { useState } from 'react';
import { useParams, useSearchParams } from 'react-router';

import Dialog from '@/components/Dialog';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  rememberWorkingLanguage,
  useNavigateToTranslation,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import { EDITED_TRANSLATION_PARAM } from '@/services/baseQuery';
import { useForkPageTranslationMutation } from '@/services/componentAndLayout';
import { waitForPreviewRequests } from '@/services/preview';
import { getLanguages } from '@/utils/drupal-globals';

// The links of a translation that give it its own layout (fork it), and that
// make it follow the layout of the original again (unfork it).
// @see \Drupal\canvas\CanvasUriDefinitions
export const FORK_LINK_REL = 'https://drupal.org/project/canvas#link-rel-fork';
export const UNFORK_LINK_REL =
  'https://drupal.org/project/canvas#link-rel-unfork';

/**
 * Asks to customize the layout of the open page in a language, then opens it
 * in the editor in that language: that is where it is edited.
 *
 * Customizing it forks the translation, in its draft: the translation gets
 * its own layout.
 *
 * @see \Drupal\canvas\Controller\ApiTranslationControllers::fork()
 */
const CustomizeLayoutDialog = ({
  languageId,
  url,
  onClose,
}: {
  // The language, while the dialog is open.
  languageId?: string;
  url?: string;
  onClose: () => void;
}) => {
  const { entityType, entityId } = useParams();
  const [searchParams] = useSearchParams();
  const { pageLanguage, statusOf } = useWorkingLanguage();
  const navigateToTranslation = useNavigateToTranslation();
  const [forkPageTranslation, { isLoading }] = useForkPageTranslationMutation();
  const [error, setError] = useState<string | null>(null);
  const nameOf = (id?: string) =>
    getLanguages().find((language) => language.id === id)?.name ?? id;
  const name = nameOf(languageId);
  const original = nameOf(pageLanguage);

  const close = () => {
    setError(null);
    onClose();
  };

  const handleConfirm = async () => {
    if (!languageId || !url) {
      return;
    }
    try {
      // The copy is made of the layout as saved.
      await waitForPreviewRequests();
      await forkPageTranslation(url).unwrap();
    } catch (e) {
      console.error('Failed to customize the layout:', e);
      setError(extractErrorMessageFromApiResponse(e));
      return;
    }
    close();
    rememberWorkingLanguage(languageId);
    // The page shown in that language is now edited in it: Back does not
    // return to showing it.
    navigateToTranslation(
      `/editor/${entityType}/${entityId}?${EDITED_TRANSLATION_PARAM}=${languageId}`,
      { replace: searchParams.get('language') === languageId },
    );
  };

  return (
    <Dialog
      open={!!languageId && !!url}
      onOpenChange={(open) => !open && close()}
      title={`Customize the ${name} layout?`}
      description={
        statusOf(languageId) === 'Not translated'
          ? `${name} starts as a copy of the ${original} page, text included, and is edited here. It is a draft until you publish it.`
          : `${name} gets its own copy of the layout, which is edited here. Changes to the layout in ${original} no longer apply to it. Nothing changes for visitors until you publish.`
      }
      error={
        error
          ? { title: 'Failed to customize the layout', message: error }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Customize layout',
        onConfirm: handleConfirm,
        onCancel: close,
        isConfirmLoading: isLoading,
      }}
    />
  );
};

export default CustomizeLayoutDialog;
