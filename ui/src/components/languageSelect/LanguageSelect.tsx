import { useRef, useState } from 'react';
import clsx from 'clsx';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  CheckIcon,
  ChevronDownIcon,
  DotsVerticalIcon,
  ExternalLinkIcon,
  GlobeIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import {
  Box,
  Button,
  DropdownMenu,
  Flex,
  Popover,
  Separator,
  Text,
} from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectTranslations } from '@/features/layout/layoutModelSlice';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import { getCanvasPermissions, getLanguages } from '@/utils/drupal-globals';
import { getEntityTitle } from '@/utils/entityTitle';

import styles from './LanguageSelect.module.css';

const LanguageSelect = () => {
  const languages = getLanguages();
  const permissions = getCanvasPermissions();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const [openPopoverId, setOpenPopoverId] = useState<string | null>(null);
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
  const isTemplateRoute = isTemplateContext || isTemplatePreviewRoute;
  const pageData = useAppSelector(selectPageData);
  const pageTitle =
    getEntityTitle(entityType, pageData) || pageData?.['title[0][value]'];

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
    const url = translations?.links?.[languageId]?.['delete-form'];
    if (url) {
      window.open(url, '_blank', 'noopener,noreferrer');
    }
    setOpenPopoverId(null);
  };

  const handleConfigureLanguages = () => {
    window.open(
      '/admin/config/regional/language',
      '_blank',
      'noopener,noreferrer',
    );
    setDropdownOpen(false);
  };

  const currentLangObj = languages.find((lang) => lang.id === currentLanguage);

  if (languages.length <= 1) {
    return null;
  }

  return (
    <DropdownMenu.Root open={dropdownOpen} onOpenChange={setDropdownOpen}>
      <DropdownMenu.Trigger>
        <Button
          size="2"
          color="gray"
          variant="soft"
          data-testid="language-select-trigger"
        >
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
                  {translations?.available?.includes(language.id) && (
                    <CheckIcon />
                  )}
                </Box>
                <Text
                  className={styles.languageName}
                  data-canvas-has-translation={
                    translations?.available?.includes(language.id)
                      ? 'true'
                      : undefined
                  }
                >
                  {language.name}
                  {language.isDefault && ' (Default)'}
                </Text>
              </Flex>
            </DropdownMenu.Item>
            {translations?.links?.[language.id] && (
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
                      onClick={(e) => e.stopPropagation()}
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
                    {translations?.links?.[language.id]?.['delete-form'] && (
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
  );
};

export default LanguageSelect;
