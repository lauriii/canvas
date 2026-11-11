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
  DotsHorizontalIcon,
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
  useTranslateAction,
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
 * Each other language of the open page or template also has its actions in
 * the menu: its translation, added and edited with a translation module, or
 * deleted. The Canvas editor edits a page in its own language.
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
  const translateActionFor = useTranslateAction();
  const [setLangcode, { isLoading: isChanging }] = useSetLangcodeMutation();
  const [deletePageTranslation, { isLoading: isDeleting }] =
    useDeletePageTranslationMutation();
  const [dialog, setDialog] = useState<{
    type: 'change' | 'delete';
    languageId: string;
  } | null>(null);
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

  // Only a Canvas page can change its language.
  const isPage = entityType === 'canvas_page' && !isTemplateRoute;
  // What can be done with the open page or template in a language. Not in
  // the preview, which only shows it.
  const actionsFor = (languageId: string) => {
    if (isPreviewRoute || !pageLanguage || languageId === pageLanguage) {
      return null;
    }
    const translate = translateActionFor(languageId);
    const deleteUrl: string | undefined =
      statusOf(languageId) === 'Translated' &&
      !isNew &&
      (isPage || isTemplateRoute)
        ? translations?.links?.[languageId]?.['delete-form']
        : undefined;
    // The language of a page can change while nothing depends on it.
    // @see canvas.api.content.langcode.patch in canvas.routing.yml
    const canChange =
      isPage && Boolean(isNew) && translations?.available?.length === 1;
    return translate || deleteUrl || canChange
      ? { translate, deleteUrl, canChange }
      : null;
  };
  const hasActions = languages.some(({ id }) => actionsFor(id));
  const dialogLanguage = languages.find(({ id }) => id === dialog?.languageId);

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
        langcode: dialog!.languageId,
      }).unwrap();
    } catch (e) {
      console.error('Failed to change the page language:', e);
      setError(extractErrorMessageFromApiResponse(e));
      return;
    }
    reloadInEditor();
  };

  const handleDelete = async () => {
    const languageId = dialog!.languageId;
    const deleteUrl = actionsFor(languageId)?.deleteUrl;
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
    // The translations of a template are only in the state: drop the
    // translation from it as well.
    const remainingLinks = { ...translations?.links };
    delete remainingLinks[languageId];
    dispatch(
      setTranslations({
        ...translations,
        available: translations?.available.filter(
          (id: string) => id !== languageId,
        ),
        links: remainingLinks,
      }),
    );
    if (languageId === workingLanguage?.id) {
      // The translation shown is gone.
      reloadInEditor();
      return;
    }
    // The page or template stays open as it is: only its translations load
    // again.
    dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
    closeDialog();
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
              const actions = actionsFor(language.id);
              return (
                <Flex key={language.id} align="center">
                  <DropdownMenu.RadioItem
                    value={language.id}
                    className={styles.option}
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
                  {actions ? (
                    <DropdownMenu.Sub>
                      <DropdownMenu.SubTrigger
                        className={styles.actions}
                        aria-label={`${language.name}: more actions`}
                        data-testid={`language-actions-${language.id}`}
                      >
                        <DotsHorizontalIcon />
                      </DropdownMenu.SubTrigger>
                      <DropdownMenu.SubContent>
                        {actions.translate && (
                          <DropdownMenu.Item
                            data-testid={`language-translate-${language.id}`}
                            onSelect={actions.translate.run}
                          >
                            {actions.translate.opensNewTab && (
                              <ExternalLinkIcon width="14" height="14" />
                            )}
                            {actions.translate.label}
                          </DropdownMenu.Item>
                        )}
                        {actions.canChange && (
                          <DropdownMenu.Item
                            data-testid={`language-change-${language.id}`}
                            onSelect={() =>
                              setDialog({
                                type: 'change',
                                languageId: language.id,
                              })
                            }
                          >
                            Change page language to {language.name}…
                          </DropdownMenu.Item>
                        )}
                        {actions.deleteUrl && (
                          <DropdownMenu.Item
                            color="red"
                            data-testid={`language-delete-${language.id}`}
                            onSelect={() =>
                              setDialog({
                                type: 'delete',
                                languageId: language.id,
                              })
                            }
                          >
                            Delete {language.name} translation…
                          </DropdownMenu.Item>
                        )}
                      </DropdownMenu.SubContent>
                    </DropdownMenu.Sub>
                  ) : (
                    // Keeps the statuses in one column.
                    hasActions && <span className={styles.noActions} />
                  )}
                </Flex>
              );
            })}
          </DropdownMenu.RadioGroup>
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
        open={dialog?.type === 'change'}
        onOpenChange={(open) => !open && closeDialog()}
        title={`Change page language to ${dialogLanguage?.name}?`}
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
        open={dialog?.type === 'delete'}
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
