import { getPageData } from '@/lib/drupal-utils';

// The language switcher example documented in
// docs/user/src/content/docs/code-components/data-fetching.mdx.
const LanguageSwitcher = () => {
  const { mainEntity } = getPageData();
  if (!mainEntity) {
    return null;
  }
  const { requestedLanguage, renderedLanguage, translations } = mainEntity;

  return (
    <nav>
      {requestedLanguage !== renderedLanguage && (
        <p>Not available in your language; showing {renderedLanguage}.</p>
      )}
      <ul>
        {translations.map(
          ({ langcode, nativeName, url, translationAvailable, current }) => (
            <li key={langcode}>
              {current ? (
                <span lang={langcode} aria-current="true">
                  {nativeName}
                </span>
              ) : (
                <a href={url} lang={langcode} hrefLang={langcode}>
                  {nativeName}
                  {!translationAvailable && ' (not translated)'}
                </a>
              )}
            </li>
          ),
        )}
      </ul>
    </nav>
  );
};

export default LanguageSwitcher;
