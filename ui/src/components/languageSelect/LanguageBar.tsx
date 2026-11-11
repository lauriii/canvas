import { useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router';
import {
  DotsHorizontalIcon,
  ExternalLinkIcon,
  LockClosedIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  DropdownMenu,
  Flex,
  IconButton,
  Text,
  TextField,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  selectTranslations,
  setInitialized,
  setTranslations,
} from '@/features/layout/layoutModelSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import {
  rememberWorkingLanguage,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import {
  componentAndLayoutApi,
  useDeletePageTranslationMutation,
  useGetPageLayoutQuery,
} from '@/services/componentAndLayout';
import { contentApi, useSetLangcodeMutation } from '@/services/content';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';
import { waitForPreviewRequests } from '@/services/preview';
import { getLanguages } from '@/utils/drupal-globals';

import styles from './LanguageSelect.module.css';

/**
 * Explains why a page or template is read-only, and offers the ways forward.
 *
 * Shown when it is open in a working language other than the language it was
 * created in: either its translation, or the original when it has no
 * translation in the working language.
 *
 * @see useWorkingLanguage
 */
const LanguageBar = () => {
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const { entityType, entityId, bundle, viewMode } = useParams();
  const { isTemplatePreviewRoute } = useTemplateRef();
  const { urlForEditor, urlForTemplateEditor } = useEditorNavigation();
  const { workingLanguage } = useWorkingLanguage();
  // The translations in the store are those of the last loaded layout, which
  // can be another page: take them from the layout of the page in the route.
  // Asking for it in the working language leaves the page data and the
  // preview in the store alone. A template has no such layout; the preview
  // stores its translations.
  const templateTranslations = useAppSelector(selectTranslations);
  // `currentData` is empty while the layout of another page loads, where
  // `data` would still be that of the previous page.
  const { currentData: layout } = useGetPageLayoutQuery(
    {
      entityId: entityId!,
      entityType: entityType!,
      language: workingLanguage?.id,
    },
    {
      skip:
        isTemplatePreviewRoute || !entityId || !entityType || !workingLanguage,
      refetchOnMountOrArgChange: true,
    },
  );
  const translations = isTemplatePreviewRoute
    ? templateTranslations
    : layout?.translations;
  // Only a Canvas page can change its language.
  const isPage = entityType === 'canvas_page' && !isTemplatePreviewRoute;
  const [setLangcode, { isLoading: isChanging }] = useSetLangcodeMutation();
  const [deletePageTranslation, { isLoading: isDeleting }] =
    useDeletePageTranslationMutation();
  const [dialog, setDialog] = useState<'change' | 'delete' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmText, setConfirmText] = useState('');

  const available: string[] = translations?.available ?? [];
  // The language the page or template was created in.
  const pageLanguage = available[0];
  if (!entityType || !entityId || !workingLanguage || !pageLanguage) {
    return null;
  }
  const editorUrl = isTemplatePreviewRoute
    ? urlForTemplateEditor({
        entityType,
        bundle: bundle!,
        viewMode: viewMode!,
        suggestedPreviewEntityId: entityId,
      })
    : urlForEditor(entityType, entityId);
  if (workingLanguage.id === pageLanguage) {
    // A page is edited in its own language: this view has nothing to explain.
    // It is reached with the browser's Back button after the language of the
    // page changed. The translations of a template can be those of another
    // one, so only a page is sent to the editor.
    return isTemplatePreviewRoute ? null : <Navigate to={editorUrl} replace />;
  }

  const view = workingLanguage.name;
  const page =
    getLanguages().find((language) => language.id === pageLanguage)?.name ??
    pageLanguage;
  const isTranslated = available.includes(workingLanguage.id);
  // The translations of a page variant are not reported.
  const knowsTranslations = entityType !== PAGE_VARIANT_ENTITY_TYPE;
  const isNew = Boolean(layout?.isNew);
  const links = translations?.links?.[workingLanguage.id] ?? {};
  // A page that was never published is not translated yet: all its content
  // is in its auto-save entry.
  const translateUrl: string | undefined = isNew
    ? undefined
    : (links['edit-form'] ?? links['create']);
  const deleteUrl: string | undefined =
    isTranslated && !isNew && (isPage || isTemplatePreviewRoute)
      ? links['delete-form']
      : undefined;
  // The language of a page can change while nothing depends on it.
  // @see canvas.api.content.langcode.patch in canvas.routing.yml
  const canChange = isPage && isNew && available.length === 1;
  const isTemplate =
    isTemplatePreviewRoute || entityType === PAGE_VARIANT_ENTITY_TYPE;
  const noun = isTemplate ? 'template' : 'page';

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
    navigate(editorUrl);
  };

  const handleChange = async () => {
    try {
      await waitForPreviewRequests();
      await setLangcode({ entityId, langcode: workingLanguage.id }).unwrap();
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
    delete remainingLinks[workingLanguage.id];
    dispatch(
      setTranslations({
        ...translations,
        available: available.filter((id) => id !== workingLanguage.id),
        links: remainingLinks,
      }),
    );
    reloadInEditor();
  };

  const translateLabel = isTranslated
    ? 'Edit translation'
    : `Translate into ${view}`;
  // The less common actions sit in a menu.
  const hasMoreActions = canChange || !!deleteUrl;

  return (
    <>
      <Flex
        role="region"
        aria-label="Language"
        data-testid="canvas-language-bar"
        className={styles.bar}
        align="center"
        justify="between"
        gap="4"
        wrap="wrap"
      >
        <Flex align="center" gap="3" minWidth="0">
          <Badge size="1" color="gray" variant="soft" highContrast>
            <LockClosedIcon width="12" height="12" aria-hidden />
            Read-only
          </Badge>
          <Text size="2" color="gray" data-testid="canvas-language-bar-message">
            {isTranslated ? (
              <>
                <Text weight="medium" highContrast>
                  {view} translation.
                </Text>{' '}
                This {noun} is written in {page}.
              </>
            ) : (
              <>
                <Text weight="medium" highContrast>
                  This {noun} is written in {page}.
                </Text>
                {knowsTranslations && ` It is not translated into ${view}.`}
              </>
            )}
          </Text>
        </Flex>
        <Flex align="center" gap="2" wrap="wrap">
          <Button
            size="1"
            variant={translateUrl ? 'outline' : 'soft'}
            color={translateUrl ? 'gray' : undefined}
            highContrast={!!translateUrl}
            data-testid="canvas-language-bar-edit"
            onClick={() => {
              rememberWorkingLanguage(pageLanguage);
              navigate(editorUrl);
            }}
          >
            Switch to {page} to edit
          </Button>
          {translateUrl && (
            <Button
              size="1"
              variant="soft"
              data-testid="canvas-language-bar-translate"
              onClick={() =>
                window.open(translateUrl, '_blank', 'noopener,noreferrer')
              }
            >
              {translateLabel}
              <ExternalLinkIcon width="12" height="12" aria-hidden />
              <span className="visually-hidden">(opens in a new tab)</span>
            </Button>
          )}
          {hasMoreActions && (
            <DropdownMenu.Root>
              <DropdownMenu.Trigger>
                <IconButton
                  size="1"
                  variant="ghost"
                  color="gray"
                  aria-label="More language actions"
                  data-testid="canvas-language-bar-more"
                >
                  <DotsHorizontalIcon />
                </IconButton>
              </DropdownMenu.Trigger>
              <DropdownMenu.Content align="end">
                {canChange && (
                  <DropdownMenu.Item
                    data-testid="canvas-language-bar-change"
                    onSelect={() => setDialog('change')}
                  >
                    Change page language to {view}…
                  </DropdownMenu.Item>
                )}
                {deleteUrl && (
                  <DropdownMenu.Item
                    color="red"
                    data-testid="canvas-language-bar-delete"
                    onSelect={() => setDialog('delete')}
                  >
                    Delete translation…
                  </DropdownMenu.Item>
                )}
              </DropdownMenu.Content>
            </DropdownMenu.Root>
          )}
        </Flex>
      </Flex>
      <Dialog
        open={dialog === 'change'}
        onOpenChange={(open) => !open && closeDialog()}
        title={`Change page language to ${view}?`}
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
        description={`You are about to delete this translation. This will permanently delete the translation from this ${noun}. This action cannot be undone.`}
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

export default LanguageBar;
