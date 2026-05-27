import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ChevronDownIcon, GlobeIcon } from '@radix-ui/react-icons';
import { Button, DropdownMenu, Flex, Text } from '@radix-ui/themes';

import { getLanguages } from '@/utils/drupal-globals';

const LanguageSelect = () => {
  const languages = getLanguages();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { entityType, entityId, width } = useParams();

  // Derive the active language directly from the URL.
  const activeLanguageId = searchParams.get('language') ?? '';
  const defaultLanguage = languages.find((lang) => lang.isDefault);
  const currentLanguage =
    activeLanguageId || defaultLanguage?.id || languages[0]?.id || '';

  const handleLanguageChange = (languageId: string) => {
    const selectedLang = languages.find((lang) => lang.id === languageId);

    if (!selectedLang || !entityType || !entityId) {
      return;
    }

    // Navigate to the editor for the default language, or to the preview with
    // a language query parameter for non-default languages.
    if (selectedLang.isDefault) {
      navigate(`/editor/${entityType}/${entityId}`);
    } else {
      // Preserve the current viewport width, defaulting to 'full' if not set.
      const currentWidth = width || 'full';
      navigate(
        `/preview/${entityType}/${entityId}/${currentWidth}?language=${languageId}`,
        {
          state: { isLanguagePreview: true, language: languageId },
        },
      );
    }
  };

  const currentLangObj = languages.find((lang) => lang.id === currentLanguage);

  if (languages.length <= 1) {
    return null;
  }

  return (
    <DropdownMenu.Root>
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
      <DropdownMenu.Content>
        {languages.map((language) => (
          <DropdownMenu.Item
            key={language.id}
            data-testid={`language-option-${language.id}`}
            onSelect={() => handleLanguageChange(language.id)}
          >
            <Flex justify="between" width="100%">
              <Text>
                {language.name}
                {language.isDefault && ' (Default)'}
              </Text>
            </Flex>
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
};

export default LanguageSelect;
