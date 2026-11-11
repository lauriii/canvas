import { useState } from 'react';
import { useHref, useLocation, useNavigate, useParams } from 'react-router';
import {
  ChevronDownIcon,
  ExternalLinkIcon,
  GlobeIcon,
} from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import {
  rememberWorkingLanguage,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import { PAGE_VARIANT_ENTITY_TYPE } from '@/services/pageVariants';
import { getCanvasPermissions, getLanguages } from '@/utils/drupal-globals';

import styles from './LanguageSelect.module.css';

/**
 * Selects the working language: the language pages are shown and created in.
 *
 * Selecting a language only navigates. The editor edits a page in the
 * language it was created in, and shows it in any other. The preview shows it
 * the way visitors see it, in the selected language.
 *
 * @see useWorkingLanguage
 * @see LanguageBar
 */
const LanguageSelect = () => {
  const languages = getLanguages();
  const permissions = getCanvasPermissions();
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const navigate = useNavigate();
  const canvasBase = useHref('/').replace(/\/$/, '');
  const { entityType, entityId, previewEntityId, bundle, viewMode, width } =
    useParams();
  const { pathname } = useLocation();
  const isPreviewRoute = pathname.startsWith('/preview/');
  const isTemplateRoute =
    pathname.startsWith('/template/') ||
    pathname.startsWith('/preview/template/');
  const { workingLanguage, pageLanguage, translations } = useWorkingLanguage();

  const handleLanguageChange = (languageId: string) => {
    const selectedLang = languages.find((lang) => lang.id === languageId);
    if (!selectedLang || languageId === workingLanguage?.id) {
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
    // Before the layout has loaded, the language of the page or template is
    // not known yet.
    const isOwnLanguage = pageLanguage
      ? languageId === pageLanguage
      : selectedLang.isDefault;
    const query = isOwnLanguage ? '' : `?language=${languageId}`;
    const templateEntityId = entityId || previewEntityId;
    // The preview shows the page the way visitors see it, in any language.
    if (isPreviewRoute) {
      navigate(
        isTemplateRoute
          ? `/preview/template/${entityType}/${bundle}/${templateEntityId}/${viewMode}/${width || 'full'}${query}`
          : `/preview/${entityType}/${entityId}/${width || 'full'}${query}`,
      );
      return;
    }
    // The editor edits a page or template in its own language, and shows it
    // in any other.
    navigate(
      isTemplateRoute
        ? `/template/${entityType}/${bundle}/${viewMode}/${templateEntityId}${query}`
        : `/editor/${entityType}/${entityId}${query}`,
    );
  };

  // How the open page or template relates to a language.
  const statusOf = (languageId: string) => {
    if (!entityType || !translations?.available) {
      return null;
    }
    if (languageId === pageLanguage) {
      return 'Original';
    }
    // The translations of a page variant are not reported.
    if (entityType === PAGE_VARIANT_ENTITY_TYPE) {
      return null;
    }
    return translations.available.includes(languageId)
      ? 'Translated'
      : 'Not translated';
  };

  if (languages.length <= 1) {
    return null;
  }

  const label = workingLanguage?.name ?? 'Select language';

  return (
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
  );
};

export default LanguageSelect;
