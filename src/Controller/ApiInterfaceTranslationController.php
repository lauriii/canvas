<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Component\Gettext\PoItem;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\locale\PluralFormulaInterface;
use Drupal\locale\StringStorageInterface;
use Drupal\locale\TranslationString;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Looks up interface translations for tools that preview code components.
 *
 * Canvas Workbench previews code components that Drupal may not store yet, so
 * it looks up the translations of their strings by language. Lookups only
 * read translations: unlike `t()`, they never register strings with Locale.
 *
 * @internal This HTTP API is intended only for Canvas tools. These controllers
 *   and associated routes may change at any time.
 */
final class ApiInterfaceTranslationController extends ApiControllerBase {

  /**
   * The most strings that one lookup may contain.
   */
  public const int MAX_STRINGS = 1000;

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ContainerInterface $container,
  ) {}

  /**
   * Lists the languages that interface text can be translated into.
   */
  public function languages(): JsonResponse {
    $languages = [];
    // Locked languages, such as "Not specified", are not interface languages.
    foreach ($this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE) as $language) {
      $languages[] = [
        'langcode' => $language->getId(),
        'label' => $language->getName(),
        'direction' => $language->getDirection(),
      ];
    }
    return new JsonResponse([
      'defaultLangcode' => $this->languageManager->getDefaultLanguage()->getId(),
      'languages' => $languages,
    ]);
  }

  /**
   * Looks up the translations of strings in a language.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The translations, in the shape of the Canvas context's `translations`.
   */
  public function lookup(Request $request, string $langcode): JsonResponse {
    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL || $language->isLocked()) {
      throw new NotFoundHttpException("The language '$langcode' does not exist.");
    }
    $strings = self::decode($request)['strings'] ?? NULL;
    if (!\is_array($strings) || !\array_is_list($strings)) {
      throw new UnprocessableEntityHttpException("The 'strings' field must be a list.");
    }
    if (\count($strings) > self::MAX_STRINGS) {
      throw new UnprocessableEntityHttpException(\sprintf("The 'strings' field must not contain more than %d strings.", self::MAX_STRINGS));
    }
    foreach ($strings as $index => $string) {
      $error = self::validateString($string);
      if ($error !== NULL) {
        throw new UnprocessableEntityHttpException("strings.$index: $error");
      }
    }

    // As `t()` translates: the strings of the site's settings first, then
    // Locale's, which translates English only when it is configured to.
    $custom_strings = Settings::get('locale_custom_strings_' . $langcode, []);
    $stored = [];
    $storage = $this->getLocaleStorage($langcode);
    if ($storage !== NULL && $strings !== []) {
      // Unlike findTranslation(), which updates the version of the strings it
      // finds, getTranslations() only reads.
      $found = $storage->getTranslations([
        'source' => \array_map(self::getLocaleSource(...), $strings),
        'language' => $langcode,
        'translated' => TRUE,
      ]);
      foreach ($found as $translation) {
        \assert($translation instanceof TranslationString);
        $stored[$translation->context][$translation->source] = $translation->translation;
      }
    }
    $translations = [];
    $has_plural = FALSE;
    foreach ($strings as $string) {
      $source = self::getLocaleSource($string);
      $context = $string['context'] ?? '';
      $translation = $custom_strings[$context][$source] ?? $stored[$context][$source] ?? NULL;
      if (!\is_string($translation) || $translation === '' || $translation === $source) {
        continue;
      }
      $translations['strings'][$context][$source] = $translation;
      $has_plural = $has_plural || \str_contains($source, PoItem::DELIMITER);
    }

    // Only the Locale module stores plural formulas.
    if ($has_plural && $this->container->has(PluralFormulaInterface::class)) {
      $plural_formula = $this->container->get(PluralFormulaInterface::class);
      \assert($plural_formula instanceof PluralFormulaInterface);
      $formula = $plural_formula->getFormula($langcode);
      if (\is_array($formula)) {
        $translations['pluralFormula'] = $formula;
      }
    }
    // An object, also without strings.
    $translations['strings'] ??= new \stdClass();
    return new JsonResponse($translations);
  }

  /**
   * Validates a string against the rules of stored translatable strings.
   *
   * The rules of `translatableStrings` in the code component config schema.
   *
   * @return string|null
   *   The error, or NULL for a valid string.
   *
   * @see config/schema/canvas.schema.yml
   */
  private static function validateString(mixed $string): ?string {
    if (!\is_array($string) || $string === [] || \array_is_list($string)) {
      return 'A string must be an object.';
    }
    if ($unknown = \array_diff(\array_keys($string), ['source', 'plural', 'context'])) {
      return \sprintf("Unknown fields: '%s'.", \implode("', '", $unknown));
    }
    if (!isset($string['source'])) {
      return "The 'source' field is required.";
    }
    foreach ($string as $field => $value) {
      if (!\is_string($value)) {
        return "The '$field' field must be a string.";
      }
      if ($value === '') {
        return "The '$field' field must not be empty.";
      }
    }
    foreach (['source', 'plural'] as $field) {
      if (isset($string[$field]) && \str_contains($string[$field], PoItem::DELIMITER)) {
        return 'A translatable string must not contain the U+0003 character, which separates plural forms.';
      }
    }
    if (isset($string['context'])) {
      if (\strlen($string['context']) > 255) {
        return 'A translation context must not be longer than 255 characters.';
      }
      if (!\preg_match('/^[\x00-\x7F]*$/', $string['context'])) {
        return 'A translation context must contain only ASCII characters.';
      }
    }
    return NULL;
  }

  /**
   * The source of a string as Locale stores it, with its plural form.
   *
   * @param array{source: string, plural?: string, context?: string} $string
   *   The string.
   */
  private static function getLocaleSource(array $string): string {
    return $string['source'] . (isset($string['plural']) ? PoItem::DELIMITER . $string['plural'] : '');
  }

  /**
   * The Locale storage, if Locale translates into the language.
   */
  private function getLocaleStorage(string $langcode): ?StringStorageInterface {
    // Only the Locale module stores translations.
    if (!$this->container->has(StringStorageInterface::class)) {
      return NULL;
    }
    // Locale translates into English only when configured to.
    // @see \Drupal\locale\LocaleTranslation::getStringTranslation()
    if ($langcode === 'en' && !$this->configFactory->get('locale.settings')->get('translate_english')) {
      return NULL;
    }
    $storage = $this->container->get(StringStorageInterface::class);
    \assert($storage instanceof StringStorageInterface);
    return $storage;
  }

}
