import { useEffect, useRef, useState } from 'react';
import {
  Navigate,
  useHref,
  useLocation,
  useNavigate,
  useParams,
} from 'react-router';
import { toast } from 'sonner';
import {
  ChevronDownIcon,
  DotsVerticalIcon,
  ExternalLinkIcon,
  GlobeIcon,
} from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import CustomizeLayoutDialog, {
  FORK_LINK_REL,
  UNFORK_LINK_REL,
} from '@/components/languageSelect/CustomizeLayoutDialog';
import { isLanguageViewPath } from '@/components/topbar/topbarPreviewMode';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  setInitialized,
  setTranslations,
} from '@/features/layout/layoutModelSlice';
import {
  getRememberedWorkingLanguage,
  rememberWorkingLanguage,
  useNavigateToTranslation,
  useTranslateAction,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import {
  EDITED_TRANSLATION_PARAM,
  getEditorLanguage,
} from '@/services/baseQuery';
import {
  componentAndLayoutApi,
  useDeletePageTranslationMutation,
  useForkPageTranslationMutation,
  useUnforkPageTranslationMutation,
} from '@/services/componentAndLayout';
import { contentApi, useSetLangcodeMutation } from '@/services/content';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';
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
 * Each other language of the open page or template also has its options in
 * the menu: its translation, added and edited with a translation module, or
 * deleted. The Canvas editor edits a page in its own language, and in a
 * language in which the page has its own layout: a forked translation, which
 * the menu can also give the page, and remove.
 *
 * @see \Drupal\canvas\ContentTranslation\ComponentTreeTranslationFork
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
  const {
    workingLanguage,
    pageLanguage,
    translations,
    isNew,
    statusOf,
    layoutStateOf,
    hasOwnLayout,
    isResolved,
  } = useWorkingLanguage();
  // The working language follows the open page or template. Say so when that
  // takes it away from the language the user selected.
  const shownLanguageId = useRef<string>(undefined);
  useEffect(() => {
    if (!isResolved || !workingLanguage) {
      return;
    }
    const previousId = shownLanguageId.current;
    shownLanguageId.current = workingLanguage.id;
    if (
      previousId &&
      previousId !== workingLanguage.id &&
      workingLanguage.id !== getRememberedWorkingLanguage()
    ) {
      toast(
        `Switched to ${workingLanguage.name}, the language of this ${isTemplateRoute ? 'template' : entityType === PAGE_VARIANT_ENTITY_TYPE ? 'page template' : 'page'}`,
        { style: { width: 'auto', padding: '5px 12px' } },
      );
    }
  }, [isResolved, workingLanguage, isTemplateRoute, entityType]);
  const translateActionFor = useTranslateAction();
  const navigateToTranslation = useNavigateToTranslation();
  const [forkPageTranslation] = useForkPageTranslationMutation();
  const [unforkPageTranslation, { isLoading: isUnforking }] =
    useUnforkPageTranslationMutation();
  const [setLangcode, { isLoading: isChanging }] = useSetLangcodeMutation();
  const [deletePageTranslation, { isLoading: isDeleting }] =
    useDeletePageTranslationMutation();
  const [dialog, setDialog] = useState<{
    type: 'change' | 'delete' | 'fork' | 'unfork' | 'keep';
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
    // The editor edits a page in a language in which it has its own layout.
    const query = isOwnLanguage
      ? ''
      : !isPreviewRoute && !isTemplateRoute && hasOwnLayout(languageId)
        ? `?${EDITED_TRANSLATION_PARAM}=${languageId}`
        : `?language=${languageId}`;
    const templateEntityId = entityId || previewEntityId;
    // The preview shows the page the way visitors see it, in any language.
    if (isPreviewRoute) {
      return isTemplateRoute
        ? `/preview/template/${entityType}/${bundle}/${templateEntityId}/${viewMode}/${width || 'full'}${query}`
        : `/preview/${entityType}/${entityId}/${width || 'full'}${query}`;
    }
    // The editor edits a page or template in its own language, and shows it
    // in any other but one in which a page has its own layout.
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
    navigateToTranslation(urlFor(languageId));
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
    const status = statusOf(languageId);
    const links = translations?.links?.[languageId] ?? {};
    // A translation that has its own layout only in a draft is deleted, which
    // discards the draft.
    const deleteUrl: string | undefined =
      (status === 'Translated' || status === 'Custom layout') &&
      !isNew &&
      (isPage || isTemplateRoute)
        ? links['delete-form']
        : undefined;
    // The language of a page can change while nothing depends on it.
    // @see canvas.api.content.langcode.patch in canvas.routing.yml
    const canChange =
      isPage && Boolean(isNew) && translations?.available?.length === 1;
    // While the removal of its own layout is not published, giving it one
    // keeps the one it has.
    // @see \Drupal\canvas\Controller\ApiTranslationControllers::fork()
    const forkUrl: string | undefined = isPage
      ? links[FORK_LINK_REL]
      : undefined;
    const isPendingUnfork = layoutStateOf(languageId) === 'pending_unfork';
    const giveOwnLayoutUrl = isPendingUnfork ? undefined : forkUrl;
    const keepOwnLayoutUrl = isPendingUnfork ? forkUrl : undefined;
    const removeOwnLayoutUrl: string | undefined = isPage
      ? links[UNFORK_LINK_REL]
      : undefined;
    return translate || deleteUrl || canChange || forkUrl || removeOwnLayoutUrl
      ? {
          translate,
          deleteUrl,
          canChange,
          giveOwnLayoutUrl,
          keepOwnLayoutUrl,
          removeOwnLayoutUrl,
        }
      : null;
  };
  const hasActions = languages.some(({ id }) => actionsFor(id));
  const dialogLanguage = languages.find(({ id }) => id === dialog?.languageId);
  const originalName =
    languages.find(({ id }) => id === pageLanguage)?.name ?? pageLanguage;
  // The language the editor edits a forked translation in, if any.
  const editedLanguage = getEditorLanguage(window.location.href);

  const closeDialog = () => {
    setDialog(null);
    setError(null);
    setConfirmText('');
  };

  // Reloads the page in the editor. Resetting `isInitialized` makes
  // LayoutLoader treat the next layout response as fresh.
  const reloadInEditor = () => {
    dispatch(setInitialized(false));
    // The editor's request for a translation that is gone would fail: the
    // app loads afresh in the original instead.
    if (!editedLanguage) {
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    }
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
    closeDialog();
    navigateToTranslation(urlFor(pageLanguage!));
  };

  // Undoes the removal of the own layout of a language, before it is
  // published.
  const handleKeepOwnLayout = async (languageId: string) => {
    const url = actionsFor(languageId)?.keepOwnLayoutUrl;
    if (!url) {
      return;
    }
    try {
      await waitForPreviewRequests();
      await forkPageTranslation(url).unwrap();
    } catch (e) {
      console.error('Failed to keep the custom layout:', e);
      setDialog({ type: 'keep', languageId });
      setError(extractErrorMessageFromApiResponse(e));
      return;
    }
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
  };

  // Makes a language follow the layout of the original again. When it is the
  // one edited, the page is then shown in it: it is no longer edited there.
  const handleUnfork = async () => {
    const languageId = dialog!.languageId;
    const url = actionsFor(languageId)?.removeOwnLayoutUrl;
    if (!url) {
      return;
    }
    try {
      // The editor may still be saving the layout of this language.
      await waitForPreviewRequests();
      await unforkPageTranslation({ url, langcode: languageId }).unwrap();
    } catch (e) {
      console.error('Failed to reset the layout:', e);
      setError(extractErrorMessageFromApiResponse(e));
      return;
    }
    closeDialog();
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
    if (editedLanguage === languageId) {
      navigateToTranslation(
        `/editor/${entityType}/${entityId}?language=${languageId}`,
        { replace: true },
      );
    }
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
      await deletePageTranslation({
        url: deleteUrl,
        langcode: languageId,
      }).unwrap();
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
    if (languageId === workingLanguage?.id || languageId === editedLanguage) {
      // The translation shown or edited is gone.
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
            aria-label={`Language: ${label}`}
          >
            <GlobeIcon />
            <Text className={styles.triggerLabel} title={label}>
              {label}
            </Text>
            <ChevronDownIcon width="16" height="16" />
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content className={styles.dropdown}>
          <DropdownMenu.RadioGroup
            value={workingLanguage?.id ?? ''}
            onValueChange={handleLanguageChange}
          >
            {languages.map((language) => {
              const status = statusOf(language.id);
              const actions = actionsFor(language.id);
              return (
                <Flex key={language.id} align="center" className={styles.row}>
                  <DropdownMenu.RadioItem
                    value={language.id}
                    color={
                      language.id === workingLanguage?.id ? 'blue' : undefined
                    }
                    className={styles.option}
                    data-testid={`language-option-${language.id}`}
                  >
                    <Flex align="center" justify="between" gap="4" width="100%">
                      <Text
                        className={styles.languageName}
                        data-canvas-has-translation={
                          status === 'Original' ||
                          status === 'Translated' ||
                          status === 'Custom layout'
                            ? 'true'
                            : undefined
                        }
                      >
                        {language.name}
                      </Text>
                      {status && (
                        <Text
                          size="1"
                          className={styles.status}
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
                        aria-label={`Language options for ${language.name}`}
                        data-testid={`language-actions-${language.id}`}
                        // Opens on a click, like the options of a page: not
                        // when the pointer passes over it. The pointer
                        // highlights the language the options belong to,
                        // unless the options of a language are open: the
                        // pointer can pass over this on its way to them.
                        // Nor do they close when the pointer leaves it in
                        // another direction than theirs.
                        onPointerLeave={(event) => event.preventDefault()}
                        onPointerMove={(event) => {
                          event.preventDefault();
                          const { currentTarget, pointerType } = event;
                          if (
                            pointerType === 'mouse' &&
                            !currentTarget
                              .closest('[role="menu"]')
                              ?.querySelector(
                                '[aria-haspopup][data-state="open"]',
                              )
                          ) {
                            (
                              currentTarget.previousElementSibling as HTMLElement | null
                            )?.focus({ preventScroll: true });
                          }
                        }}
                      >
                        <DotsVerticalIcon />
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
                            Change page language…
                          </DropdownMenu.Item>
                        )}
                        {actions.giveOwnLayoutUrl && (
                          <DropdownMenu.Item
                            data-testid={`language-fork-${language.id}`}
                            onSelect={() =>
                              setDialog({
                                type: 'fork',
                                languageId: language.id,
                              })
                            }
                          >
                            Customize layout…
                          </DropdownMenu.Item>
                        )}
                        {actions.keepOwnLayoutUrl && (
                          <DropdownMenu.Item
                            data-testid={`language-keep-${language.id}`}
                            onSelect={() => handleKeepOwnLayout(language.id)}
                          >
                            Keep custom layout
                          </DropdownMenu.Item>
                        )}
                        {actions.removeOwnLayoutUrl && (
                          <DropdownMenu.Item
                            color="red"
                            data-testid={`language-unfork-${language.id}`}
                            onSelect={() =>
                              setDialog({
                                type: 'unfork',
                                languageId: language.id,
                              })
                            }
                          >
                            Reset layout…
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
                            Delete translation…
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
      <CustomizeLayoutDialog
        languageId={dialog?.type === 'fork' ? dialog.languageId : undefined}
        url={
          dialog?.type === 'fork'
            ? actionsFor(dialog.languageId)?.giveOwnLayoutUrl
            : undefined
        }
        onClose={closeDialog}
      />
      <Dialog
        open={dialog?.type === 'keep'}
        onOpenChange={(open) => !open && closeDialog()}
        title="Keep custom layout"
        error={
          error
            ? { title: 'Failed to keep the custom layout', message: error }
            : undefined
        }
        footer={{ hidden: true }}
      />
      <Dialog
        open={dialog?.type === 'unfork'}
        onOpenChange={(open) => !open && closeDialog()}
        title={`Reset the ${dialogLanguage?.name} layout to ${originalName}?`}
        description={`${dialogLanguage?.name} follows the ${originalName} layout again. Its text is kept for the components that are also in ${originalName}; the components only in ${dialogLanguage?.name} are removed. Nothing changes for visitors until you publish.`}
        error={
          error
            ? { title: 'Failed to reset the layout', message: error }
            : undefined
        }
        footer={{
          cancelText: 'Cancel',
          confirmText: 'Reset layout',
          onConfirm: handleUnfork,
          onCancel: closeDialog,
          isConfirmDisabled: confirmText !== 'RESET',
          isConfirmLoading: isUnforking,
          isDanger: true,
        }}
      >
        <Flex direction="column" gap="2">
          <Text size="2">
            To confirm this, type '<Text weight="bold">RESET</Text>'
            <Text color="red">*</Text>
          </Text>
          <TextField.Root
            aria-label="Type RESET to confirm"
            data-testid="unfork-translation-confirm-input"
            value={confirmText}
            onChange={(e) => setConfirmText(e.target.value)}
          />
        </Flex>
      </Dialog>
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
