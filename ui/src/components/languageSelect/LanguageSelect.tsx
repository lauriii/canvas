import { useState } from 'react';
import {
  Navigate,
  useHref,
  useLocation,
  useNavigate,
  useParams,
} from 'react-router';
import {
  ChevronDownIcon,
  ExternalLinkIcon,
  GlobeIcon,
} from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { isLanguageViewPath } from '@/components/topbar/topbarPreviewMode';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  setInitialized,
  setTranslations,
} from '@/features/layout/layoutModelSlice';
import {
  rememberWorkingLanguage,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import {
  componentAndLayoutApi,
  useDeletePageTranslationMutation,
} from '@/services/componentAndLayout';
import { contentApi, useSetLangcodeMutation } from '@/services/content';
import { waitForPreviewRequests } from '@/services/preview';
import { getCanvasPermissions, getLanguages } from '@/utils/drupal-globals';

import styles from './LanguageSelect.module.css';

/**
 * Selects the working language: the language pages are shown and created in.
 *
 * Selecting a language only navigates. The editor edits a page in the
 * language it was created in, and shows it in any other. The preview shows it
 * the way visitors see it, in the selected language.
 *
 * While a page or template is shown in another language than its own, the
 * menu also holds the actions on it in that language. The Canvas editor edits
 * a page in its own language; a translation module edits its translations.
 *
 * @see useWorkingLanguage
 */
const LanguageSelect = () => {
  const languages = getLanguages();
  const permissions = getCanvasPermissions();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const canvasBase = useHref('/').replace(/\/$/, '');
  const { entityType, entityId, previewEntityId, bundle, viewMode, width } =
    useParams();
  const { pathname, search } = useLocation();
  const isPreviewRoute = pathname.startsWith('/preview/');
  const isTemplateRoute =
    pathname.startsWith('/template/') ||
    pathname.startsWith('/preview/template/');
  const { workingLanguage, pageLanguage, translations, isNew, statusOf } =
    useWorkingLanguage();
  const [setLangcode, { isLoading: isChanging }] = useSetLangcodeMutation();
  const [deletePageTranslation, { isLoading: isDeleting }] =
    useDeletePageTranslationMutation();
  const [dialog, setDialog] = useState<'change' | 'delete' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmText, setConfirmText] = useState('');

  // Where the open page or template is shown in a language.
  const urlFor = (languageId: string) => {
    // Before the layout has loaded, the language of the page or template is
    // not known yet.
    const isOwnLanguage = pageLanguage
      ? languageId === pageLanguage
      : languages.find((language) => language.id === languageId)?.isDefault;
    const query = isOwnLanguage ? '' : `?language=${languageId}`;
    const templateEntityId = entityId || previewEntityId;
    // The preview shows the page the way visitors see it, in any language.
    if (isPreviewRoute) {
      return isTemplateRoute
        ? `/preview/template/${entityType}/${bundle}/${templateEntityId}/${viewMode}/${width || 'full'}${query}`
        : `/preview/${entityType}/${entityId}/${width || 'full'}${query}`;
    }
    // The editor edits a page or template in its own language, and shows it
    // in any other.
    return isTemplateRoute
      ? `/template/${entityType}/${bundle}/${viewMode}/${templateEntityId}${query}`
      : `/editor/${entityType}/${entityId}${query}`;
  };

  const handleLanguageChange = (languageId: string) => {
    if (
      !languages.some((language) => language.id === languageId) ||
      languageId === workingLanguage?.id
    ) {
      return;
    }
    rememberWorkingLanguage(languageId);
    // Without an open page or template there is nothing to show in another
    // language: only the working language changes.
    if (!entityType || (!entityId && !previewEntityId)) {
      // Not the router's path: a page extension changes the address without
      // it.
      navigate(
        `${window.location.pathname.slice(canvasBase.length)}?language=${languageId}`,
      );
      return;
    }
    navigate(urlFor(languageId));
  };

  if (languages.length <= 1) {
    return null;
  }

  const label = workingLanguage?.name ?? 'Select language';

  // The open page or template, shown in another language than its own.
  const isLanguageView =
    isLanguageViewPath(pathname, search) &&
    !!workingLanguage &&
    !!pageLanguage &&
    workingLanguage.id !== pageLanguage;
  // Only a Canvas page can change its language.
  const isPage = entityType === 'canvas_page' && !isTemplateRoute;
  const isTranslated =
    isLanguageView && statusOf(workingLanguage.id) === 'Translated';
  const links =
    (isLanguageView && translations?.links?.[workingLanguage.id]) || {};
  // A page that was never published is not translated yet: all its content
  // is in its auto-save entry.
  const translateUrl: string | undefined = isNew
    ? undefined
    : (links['edit-form'] ?? links['create']);
  // A page extension opens in Canvas, any other translation form in a new tab.
  const inCanvasTranslatePath = translateUrl?.startsWith(`${canvasBase}/`)
    ? translateUrl.slice(canvasBase.length)
    : undefined;
  const deleteUrl: string | undefined =
    isTranslated && !isNew && (isPage || isTemplateRoute)
      ? links['delete-form']
      : undefined;
  // The language of a page can change while nothing depends on it.
  // @see canvas.api.content.langcode.patch in canvas.routing.yml
  const canChange =
    isLanguageView &&
    isPage &&
    Boolean(isNew) &&
    translations?.available?.length === 1;

  const closeDialog = () => {
    setDialog(null);
    setError(null);
    setConfirmText('');
  };

  // Reloads the page in the editor. Resetting `isInitialized` makes
  // LayoutLoader treat the next layout response as fresh.
  const reloadInEditor = () => {
    dispatch(setInitialized(false));
    dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
    closeDialog();
    navigate(urlFor(pageLanguage!));
  };

  const handleChange = async () => {
    try {
      await waitForPreviewRequests();
      await setLangcode({
        entityId: entityId!,
        langcode: workingLanguage!.id,
      }).unwrap();
    } catch (e) {
      console.error('Failed to change the page language:', e);
      setError(extractErrorMessageFromApiResponse(e));
      return;
    }
    reloadInEditor();
  };

  const handleDelete = async () => {
    if (!deleteUrl) {
      return;
    }
    try {
      await deletePageTranslation(deleteUrl).unwrap();
    } catch (e) {
      console.error('Failed to delete translation:', e);
      setError('Failed to delete the translation. Please try again.');
      return;
    }
    // Not every layout is re-fetched on return to the editor: drop the
    // translation from the state as well.
    const remainingLinks = { ...translations?.links };
    delete remainingLinks[workingLanguage!.id];
    dispatch(
      setTranslations({
        ...translations,
        available: translations?.available.filter(
          (id: string) => id !== workingLanguage!.id,
        ),
        links: remainingLinks,
      }),
    );
    reloadInEditor();
  };

  return (
    <>
      {/* A page is edited in its own language. This is reached with the
          browser's Back button after the language of the page changed. The
          translations of a template can be those of another one, so only a
          page is sent to the editor. */}
      {isLanguageViewPath(pathname, search) &&
        !isTemplateRoute &&
        !!pageLanguage &&
        workingLanguage?.id === pageLanguage && (
          <Navigate to={urlFor(pageLanguage)} replace />
        )}
      <DropdownMenu.Root open={dropdownOpen} onOpenChange={setDropdownOpen}>
        <DropdownMenu.Trigger>
          <Button
            size="1"
            variant="soft"
            data-testid="language-select-trigger"
            aria-label={`Working language: ${label}`}
          >
            <GlobeIcon />
            <Text className={styles.triggerLabel} title={label}>
              {label}
            </Text>
            <ChevronDownIcon width="16" height="16" />
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content className={styles.dropdown}>
          <DropdownMenu.Label>Working language</DropdownMenu.Label>
          <DropdownMenu.RadioGroup
            value={workingLanguage?.id ?? ''}
            onValueChange={handleLanguageChange}
          >
            {languages.map((language) => {
              const status = statusOf(language.id);
              return (
                <DropdownMenu.RadioItem
                  key={language.id}
                  value={language.id}
                  data-testid={`language-option-${language.id}`}
                >
                  <Flex align="center" justify="between" gap="4" width="100%">
                    <Text
                      className={styles.languageName}
                      data-canvas-has-translation={
                        status === 'Original' || status === 'Translated'
                          ? 'true'
                          : undefined
                      }
                    >
                      {language.name}
                    </Text>
                    {status && (
                      <Text
                        size="1"
                        color="gray"
                        data-testid={`language-status-${language.id}`}
                      >
                        {status}
                      </Text>
                    )}
                  </Flex>
                </DropdownMenu.RadioItem>
              );
            })}
          </DropdownMenu.RadioGroup>
          {(translateUrl || canChange || deleteUrl) && (
            <>
              <DropdownMenu.Separator />
              {translateUrl && (
                <DropdownMenu.Item
                  data-testid="canvas-language-translate"
                  onSelect={() => {
                    if (inCanvasTranslatePath) {
                      // The extension shows the working language too.
                      rememberWorkingLanguage(workingLanguage!.id);
                      navigate(inCanvasTranslatePath);
                    } else {
                      window.open(
                        translateUrl,
                        '_blank',
                        'noopener,noreferrer',
                      );
                    }
                  }}
                >
                  {!inCanvasTranslatePath && (
                    <ExternalLinkIcon width="14" height="14" />
                  )}
                  {isTranslated ? 'Edit' : 'Add'} {label} translation
                </DropdownMenu.Item>
              )}
              {canChange && (
                <DropdownMenu.Item
                  data-testid="canvas-language-change"
                  onSelect={() => setDialog('change')}
                >
                  Change page language to {label}…
                </DropdownMenu.Item>
              )}
              {deleteUrl && (
                <DropdownMenu.Item
                  color="red"
                  data-testid="canvas-language-delete"
                  onSelect={() => setDialog('delete')}
                >
                  Delete {label} translation…
                </DropdownMenu.Item>
              )}
            </>
          )}
          {permissions?.configureLanguages && (
            <>
              <DropdownMenu.Separator />
              <DropdownMenu.Item
                data-testid="language-configure-button"
                onSelect={() =>
                  window.open(
                    '/admin/config/regional/language',
                    '_blank',
                    'noopener,noreferrer',
                  )
                }
              >
                <ExternalLinkIcon width="14" height="14" />
                Configure languages
              </DropdownMenu.Item>
            </>
          )}
        </DropdownMenu.Content>
      </DropdownMenu.Root>
      <Dialog
        open={dialog === 'change'}
        onOpenChange={(open) => !open && closeDialog()}
        title={`Change page language to ${label}?`}
        description="The text on the page stays the same. Nothing is translated."
        error={
          error
            ? { title: 'Failed to change the page language', message: error }
            : undefined
        }
        footer={{
          cancelText: 'Cancel',
          confirmText: 'Change language',
          onConfirm: handleChange,
          onCancel: closeDialog,
          isConfirmLoading: isChanging,
        }}
      />
      <Dialog
        open={dialog === 'delete'}
        onOpenChange={(open) => !open && closeDialog()}
        title="Delete translation"
        description={`You are about to delete this translation. This will permanently delete the translation from this ${isTemplateRoute ? 'template' : 'page'}. This action cannot be undone.`}
        error={
          error
            ? { title: 'Failed to delete translation', message: error }
            : undefined
        }
        footer={{
          cancelText: 'Cancel',
          confirmText: 'Delete Translation',
          onConfirm: handleDelete,
          onCancel: closeDialog,
          isConfirmDisabled: confirmText !== 'DELETE',
          isConfirmLoading: isDeleting,
          isDanger: true,
        }}
      >
        <Flex direction="column" gap="2">
          <Text size="2">
            To confirm this, type '<Text weight="bold">DELETE</Text>'
            <Text color="red">*</Text>
          </Text>
          <TextField.Root
            data-testid="delete-translation-confirm-input"
            value={confirmText}
            onChange={(e) => setConfirmText(e.target.value)}
          />
        </Flex>
      </Dialog>
    </>
  );
};

export default LanguageSelect;
