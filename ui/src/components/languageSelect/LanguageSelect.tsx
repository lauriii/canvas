import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  CheckIcon,
  ChevronDownIcon,
  DotsVerticalIcon,
  ExternalLinkIcon,
  GlobeIcon,
  Link2Icon,
  LinkBreak2Icon,
  TrashIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Button,
  DropdownMenu,
  Flex,
  Popover,
  Separator,
  Text,
  TextField,
} from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import { selectTranslations } from '@/features/layout/layoutModelSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { selectSnapshotTitle } from '@/features/pagePreview/previewSlice';
import { useTemplateCaption } from '@/hooks/useTemplateCaption';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import {
  useDeletePageTranslationMutation,
  useForkPageTranslationMutation,
  useUnforkPageTranslationMutation,
} from '@/services/componentAndLayout';
import { getCanvasPermissions, getLanguages } from '@/utils/drupal-globals';
import { getEntityTitle } from '@/utils/entityTitle';

import styles from './LanguageSelect.module.css';

// Link relations emitted by the layout API for translation fork state.
// @see \Drupal\canvas\CanvasUriDefinitions
const FORK_LINK_REL = 'https://drupal.org/project/canvas#link-rel-fork';
const UNFORK_LINK_REL = 'https://drupal.org/project/canvas#link-rel-unfork';

interface DeleteTranslationDialogProps {
  languageId: string | null;
  url: string | undefined;
  onSuccess: (languageId: string) => void;
  onClose: () => void;
}

const DeleteTranslationDialog = ({
  languageId,
  url,
  onSuccess,
  onClose,
}: DeleteTranslationDialogProps) => {
  const [confirmText, setConfirmText] = useState('');
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deletePageTranslation] = useDeletePageTranslationMutation();

  // Reset the form whenever the dialog is dismissed.
  useEffect(() => {
    if (languageId === null) {
      setConfirmText('');
      setDeleteError(null);
    }
  }, [languageId]);

  const handleConfirmDelete = async () => {
    if (!languageId) return;
    if (url) {
      try {
        await deletePageTranslation(url).unwrap();
        onSuccess(languageId);
      } catch (error) {
        console.error('Failed to delete translation:', error);
        setDeleteError('Failed to delete the translation. Please try again.');
      }
    } else {
      onClose();
    }
  };

  return (
    <Dialog
      open={!!languageId}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      title="Delete translation"
      description="You are about to delete this translation. This will permanently delete the translation from this page. This action cannot be undone."
      error={
        deleteError
          ? {
              title: 'Failed to delete translation',
              message: deleteError,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Delete Translation',
        onConfirm: handleConfirmDelete,
        onCancel: onClose,
        isConfirmDisabled: confirmText !== 'DELETE',
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
  );
};

interface ForkTranslationDialogProps {
  languageId: string | null;
  languageName: string | undefined;
  url: string | undefined;
  onClose: () => void;
}

const ForkTranslationDialog = ({
  languageId,
  languageName,
  url,
  onClose,
}: ForkTranslationDialogProps) => {
  const [forkError, setForkError] = useState<string | null>(null);
  const [forkPageTranslation, { isLoading: isForkLoading }] =
    useForkPageTranslationMutation();

  // Reset on every open and close, so a late failure from a dismissed dialog
  // never leaks into the next one.
  useEffect(() => {
    setForkError(null);
  }, [languageId]);

  const handleConfirmFork = async () => {
    if (!languageId) {
      onClose();
      return;
    }
    if (!url) {
      // The layout refetched while the dialog was open and the fork link is
      // gone (e.g. another session already forked this translation).
      setForkError(
        'The translation state changed while this dialog was open. Close it and check the language menu again.',
      );
      return;
    }
    try {
      await forkPageTranslation(url).unwrap();
      onClose();
    } catch (error) {
      console.error('Failed to fork translation:', error);
      setForkError(
        'Failed to translate the layout independently. Please try again.',
      );
    }
  };

  return (
    <Dialog
      open={!!languageId}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      title="Translate layout independently"
      description={`The ${languageName ?? ''} translation will get its own copy of the layout. Changes to the original layout will no longer apply to it. You can revert to the synced layout later, but any independent changes will then be lost.`}
      error={
        forkError
          ? {
              title: 'Failed to translate layout independently',
              message: forkError,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Translate independently',
        onConfirm: handleConfirmFork,
        onCancel: onClose,
        isConfirmLoading: isForkLoading,
        isConfirmDisabled: isForkLoading,
      }}
    />
  );
};

interface UnforkTranslationDialogProps {
  languageId: string | null;
  languageName: string | undefined;
  url: string | undefined;
  onClose: () => void;
}

const UnforkTranslationDialog = ({
  languageId,
  languageName,
  url,
  onClose,
}: UnforkTranslationDialogProps) => {
  const [confirmText, setConfirmText] = useState('');
  const [unforkError, setUnforkError] = useState<string | null>(null);
  const [unforkPageTranslation, { isLoading: isUnforkLoading }] =
    useUnforkPageTranslationMutation();

  // Reset on every open and close, so a late failure from a dismissed dialog
  // never leaks into the next one.
  useEffect(() => {
    setConfirmText('');
    setUnforkError(null);
  }, [languageId]);

  const handleConfirmUnfork = async () => {
    if (!languageId) {
      onClose();
      return;
    }
    if (!url) {
      // The layout refetched while the dialog was open and the unfork link is
      // gone (e.g. another session already reverted this translation).
      setUnforkError(
        'The translation state changed while this dialog was open. Close it and check the language menu again.',
      );
      return;
    }
    try {
      await unforkPageTranslation(url).unwrap();
      onClose();
    } catch (error) {
      console.error('Failed to revert to synced layout:', error);
      setUnforkError(
        'Failed to revert to the synced layout. Please try again.',
      );
    }
  };

  return (
    <Dialog
      open={!!languageId}
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      title="Revert to synced layout"
      description={`The independent ${languageName ?? ''} layout will be replaced by the synced layout. Translated text is kept for components that exist in the synced layout; everything else will be permanently lost when published.`}
      error={
        unforkError
          ? {
              title: 'Failed to revert to synced layout',
              message: unforkError,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Revert to synced layout',
        onConfirm: handleConfirmUnfork,
        onCancel: onClose,
        isConfirmDisabled: confirmText !== 'REVERT' || isUnforkLoading,
        isConfirmLoading: isUnforkLoading,
        isDanger: true,
      }}
    >
      <Flex direction="column" gap="2">
        <Text size="2">
          To confirm this, type '<Text weight="bold">REVERT</Text>'
          <Text color="red">*</Text>
        </Text>
        <TextField.Root
          data-testid="unfork-translation-confirm-input"
          value={confirmText}
          onChange={(e) => setConfirmText(e.target.value)}
        />
      </Flex>
    </Dialog>
  );
};

const LanguageSelect = () => {
  const languages = getLanguages();
  const permissions = getCanvasPermissions();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [openPopoverId, setOpenPopoverId] = useState<string | null>(null);
  const [deleteLanguageId, setDeleteLanguageId] = useState<string | null>(null);
  const [forkLanguageId, setForkLanguageId] = useState<string | null>(null);
  const [unforkLanguageId, setUnforkLanguageId] = useState<string | null>(null);
  // Languages whose translation was deleted in-session. Used to hide their
  // check mark and options trigger until the layout refetch lands; reset
  // below whenever fresh translations metadata arrives.
  const [removedLanguages, setRemovedLanguages] = useState<string[]>([]);
  const popoverOffsetsRef = useRef<Record<string, number>>({});
  const rowRefs = useRef<Record<string, HTMLDivElement | null>>({});

  const handlePopoverOpenChange = (languageId: string, open: boolean) => {
    if (open) {
      const rowEl = rowRefs.current[languageId];
      if (rowEl) {
        const dotsBtn = rowEl.querySelector('button');
        popoverOffsetsRef.current[languageId] =
          rowEl.offsetWidth - (dotsBtn?.offsetWidth ?? 0) + 12;
      }
    }
    setOpenPopoverId(open ? languageId : null);
  };
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { entityType, entityId, width, previewEntityId, bundle, viewMode } =
    useParams();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const translations = useAppSelector(selectTranslations);

  // Fresh translations metadata (a layout refetch) supersedes the in-session
  // deletion bookkeeping: rows are hidden or shown by the data itself again.
  useEffect(() => {
    setRemovedLanguages([]);
  }, [translations]);
  const isTemplateRoute = isTemplateContext || isTemplatePreviewRoute;
  const templateCaption = useTemplateCaption();
  const pageData = useAppSelector(selectPageData);
  const snapshotTitle = useAppSelector(selectSnapshotTitle);
  const pageTitle =
    templateCaption ||
    snapshotTitle ||
    getEntityTitle(entityType, pageData) ||
    pageData?.['title[0][value]'];

  // Derive the active language directly from the URL.
  const activeLanguageId = searchParams.get('language') ?? '';
  const defaultLanguage = languages.find((lang) => lang.isDefault);
  const currentLanguage =
    activeLanguageId || defaultLanguage?.id || languages[0]?.id || '';

  const handleLanguageChange = (languageId: string) => {
    setDropdownOpen(false);
    const selectedLang = languages.find((lang) => lang.id === languageId);

    if (!selectedLang || !entityType || (!entityId && !previewEntityId)) {
      return;
    }

    if (selectedLang.isDefault) {
      if (isTemplateRoute) {
        navigate(
          `/template/${entityType}/${bundle}/${viewMode}/${entityId || previewEntityId}`,
        );
      } else {
        navigate(`/editor/${entityType}/${entityId}`);
      }
    } else {
      const currentWidth = width || 'full';
      if (isTemplateRoute) {
        navigate(
          `/preview/template/${entityType}/${bundle}/${entityId || previewEntityId}/${viewMode}/${currentWidth}?language=${languageId}`,
        );
      } else {
        navigate(
          `/preview/${entityType}/${entityId}/${currentWidth}?language=${languageId}`,
        );
      }
    }
  };

  const handleTranslate = (languageId: string) => {
    const url =
      translations?.links?.[languageId]?.['edit-form'] ??
      translations?.links?.[languageId]?.['create'];
    if (url) {
      window.open(url, '_blank', 'noopener,noreferrer');
    }
    setOpenPopoverId(null);
  };

  const handleDeleteTranslation = (languageId: string) => {
    setDeleteLanguageId(languageId);
    setOpenPopoverId(null);
  };

  const handleForkTranslation = (languageId: string) => {
    setForkLanguageId(languageId);
    setOpenPopoverId(null);
  };

  const handleUnforkTranslation = (languageId: string) => {
    setUnforkLanguageId(languageId);
    setOpenPopoverId(null);
  };

  // A language whose translation owns an independent (forked) layout.
  const isForked = (languageId: string) =>
    Boolean(translations?.forked?.includes(languageId));

  const handleConfigureLanguages = () => {
    window.open(
      '/admin/config/regional/language',
      '_blank',
      'noopener,noreferrer',
    );
    setDropdownOpen(false);
  };

  const currentLangObj = languages.find((lang) => lang.id === currentLanguage);

  // A language counts as translated only if the layout reports it and it has
  // not been deleted in this session.
  const hasTranslation = (languageId: string) =>
    Boolean(translations?.available?.includes(languageId)) &&
    !removedLanguages.includes(languageId);

  if (languages.length <= 1) {
    return null;
  }

  return (
    <>
      <DropdownMenu.Root
        open={dropdownOpen}
        onOpenChange={(open) => {
          // Keep the dropdown open while a confirmation dialog is showing.
          if (!open && (deleteLanguageId || forkLanguageId || unforkLanguageId))
            return;
          setDropdownOpen(open);
        }}
      >
        <DropdownMenu.Trigger>
          <Button size="2" variant="soft" data-testid="language-select-trigger">
            <GlobeIcon />
            <Text>{currentLangObj?.name || 'Select Language'}</Text>
            <ChevronDownIcon width="16" height="16" />
          </Button>
        </DropdownMenu.Trigger>
        <DropdownMenu.Content className={styles.dropdown}>
          {languages.map((language) => (
            <Flex
              key={language.id}
              ref={(el) => {
                rowRefs.current[language.id] = el;
              }}
              align="center"
              justify="between"
              className={styles.row}
            >
              <DropdownMenu.Item
                data-testid={`language-option-${language.id}`}
                onSelect={() => handleLanguageChange(language.id)}
              >
                <Flex align="center" width="100%">
                  <Box className={styles.checkIconContainer}>
                    {hasTranslation(language.id) && <CheckIcon />}
                  </Box>
                  <Text
                    className={styles.languageName}
                    data-canvas-has-translation={
                      hasTranslation(language.id) ? 'true' : undefined
                    }
                  >
                    {language.name}
                    {language.isDefault && ' (Default)'}
                  </Text>
                  {isForked(language.id) && (
                    <Badge
                      color="amber"
                      size="1"
                      ml="1"
                      data-testid={`language-forked-badge-${language.id}`}
                    >
                      Independent
                    </Badge>
                  )}
                </Flex>
              </DropdownMenu.Item>
              {translations?.links?.[language.id] &&
                !removedLanguages.includes(language.id) && (
                  <Popover.Root
                    open={openPopoverId === language.id}
                    onOpenChange={(open) =>
                      handlePopoverOpenChange(language.id, open)
                    }
                  >
                    <DropdownMenu.Item
                      onSelect={(e) => {
                        e.preventDefault();
                        handlePopoverOpenChange(language.id, true);
                      }}
                    >
                      <Popover.Trigger>
                        <button
                          data-testid="language-options-popover-trigger"
                          className={styles.dotsButton}
                          aria-label={`More options for ${language.name}`}
                          onClick={(e) => {
                            e.stopPropagation();
                            handlePopoverOpenChange(language.id, true);
                          }}
                        >
                          <DotsVerticalIcon width="14" height="14" />
                        </button>
                      </Popover.Trigger>
                    </DropdownMenu.Item>
                    <Popover.Content
                      side="left"
                      sideOffset={popoverOffsetsRef.current[language.id] ?? 0}
                      align="start"
                      className={styles.popover}
                      data-testid="language-options-popover"
                      onPointerDownOutside={(e) => {
                        e.preventDefault();
                        setOpenPopoverId(null);
                      }}
                      onInteractOutside={(e) => {
                        e.preventDefault();
                      }}
                    >
                      <Flex direction="column" gap="1">
                        <Text
                          size="2"
                          weight="medium"
                          className={styles.popoverTitle}
                          data-testid="language-options-popover-title"
                        >
                          {pageTitle || 'Untitled'} ({language.name})
                        </Text>
                        <Separator size="4" my="1" />
                        {(translations?.links?.[language.id]?.['edit-form'] ||
                          translations?.links?.[language.id]?.['create']) && (
                          <button
                            className={styles.popoverItem}
                            onClick={() => handleTranslate(language.id)}
                          >
                            <ExternalLinkIcon width="14" height="14" />
                            <Text size="2">
                              {translations?.links?.[language.id]?.['edit-form']
                                ? 'Edit translation'
                                : 'Add translation'}
                            </Text>
                          </button>
                        )}
                        {translations?.links?.[language.id]?.[
                          FORK_LINK_REL
                        ] && (
                          <button
                            className={styles.popoverItem}
                            data-testid="language-options-fork"
                            onClick={() => handleForkTranslation(language.id)}
                          >
                            <LinkBreak2Icon width="14" height="14" />
                            <Text size="2">Translate layout independently</Text>
                          </button>
                        )}
                        {translations?.links?.[language.id]?.[
                          UNFORK_LINK_REL
                        ] && (
                          <button
                            className={clsx(
                              styles.popoverItem,
                              styles.popoverItemRed,
                            )}
                            data-testid="language-options-unfork"
                            onClick={() => handleUnforkTranslation(language.id)}
                          >
                            <Link2Icon width="14" height="14" />
                            <Text size="2">Revert to synced layout</Text>
                          </button>
                        )}
                        {translations?.links?.[language.id]?.[
                          'delete-form'
                        ] && (
                          <button
                            className={clsx(
                              styles.popoverItem,
                              styles.popoverItemRed,
                            )}
                            data-testid="language-options-delete"
                            onClick={() => handleDeleteTranslation(language.id)}
                          >
                            <TrashIcon width="14" height="14" />
                            <Text size="2">Delete translation</Text>
                          </button>
                        )}
                      </Flex>
                    </Popover.Content>
                  </Popover.Root>
                )}
            </Flex>
          ))}
          <Separator size="4" my="2" />
          {permissions?.configureLanguages && (
            <DropdownMenu.Item onSelect={handleConfigureLanguages}>
              <button
                className={styles.configureButton}
                data-testid="language-configure-button"
              >
                <Flex align="center" gap="2">
                  <ExternalLinkIcon width="14" height="14" />
                  <Text size="2">Configure languages</Text>
                </Flex>
              </button>
            </DropdownMenu.Item>
          )}
        </DropdownMenu.Content>
      </DropdownMenu.Root>
      <DeleteTranslationDialog
        languageId={deleteLanguageId}
        url={
          deleteLanguageId
            ? translations?.links?.[deleteLanguageId]?.['delete-form']
            : undefined
        }
        onSuccess={(languageId) => {
          setRemovedLanguages((prev) => [...prev, languageId]);
          setDeleteLanguageId(null);
        }}
        onClose={() => setDeleteLanguageId(null)}
      />
      <ForkTranslationDialog
        languageId={forkLanguageId}
        languageName={
          languages.find((lang) => lang.id === forkLanguageId)?.name
        }
        url={
          forkLanguageId
            ? translations?.links?.[forkLanguageId]?.[FORK_LINK_REL]
            : undefined
        }
        onClose={() => setForkLanguageId(null)}
      />
      <UnforkTranslationDialog
        languageId={unforkLanguageId}
        languageName={
          languages.find((lang) => lang.id === unforkLanguageId)?.name
        }
        url={
          unforkLanguageId
            ? translations?.links?.[unforkLanguageId]?.[UNFORK_LINK_REL]
            : undefined
        }
        onClose={() => setUnforkLanguageId(null)}
      />
    </>
  );
};

export default LanguageSelect;
