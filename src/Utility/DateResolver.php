<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Resolves ISO date/time strings to locale-formatted strings.
 *
 * This is an opt-in utility for component creators. Props reach templates as
 * raw ISO strings by default. Use this service (or its Twig filter wrappers)
 * when you want to display a date in the active Drupal interface language's
 * locale format.
 *
 * @internal
 */
final class DateResolver {

  /**
   * Mirrors \IntlDateFormatter::NONE (-1).
   *
   * Defined locally so this class loads even when the intl extension is
   * absent; the fallback path does not call \IntlDateFormatter at all.
   */
  private const INTL_DATE_NONE = -1;

  /**
   * Mirrors \IntlDateFormatter::SHORT (3).
   *
   * Defined locally so this class loads even when the intl extension is
   * absent; the fallback path does not call \IntlDateFormatter at all.
   */
  private const INTL_DATE_SHORT = 3;

  /**
   * Constructs a DateResolver.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service, used to read the active interface
   *   language.
   * @param bool|null $intlAvailable
   *   For testing only: TRUE forces the intl path, FALSE forces the fallback
   *   path. NULL (default) auto-detects via extension_loaded('intl').
   */
  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ?bool $intlAvailable = NULL,
  ) {}

  /**
   * Formats an ISO date string using the active locale's short date format.
   *
   * @param string $iso
   *   ISO date string, e.g. '2026-01-15'.
   *
   * @return string
   *   Formatted date, e.g. '1/15/26' (en) or '15/01/2026' (fr).
   *   Returns $iso unchanged if it cannot be parsed.
   */
  public function resolveDate(string $iso): string {
    return $this->resolveLocalizedDateTime(
      $iso,
      self::INTL_DATE_SHORT,
      self::INTL_DATE_NONE,
    );
  }

  /**
   * Formats an ISO datetime string using the active locale's short format.
   *
   * @param string $iso
   *   ISO datetime string, e.g. '2026-01-15T14:30:00Z'.
   *
   * @return string
   *   Formatted date+time string. Returns $iso unchanged if unparseable.
   */
  public function resolveDateTime(string $iso): string {
    return $this->resolveLocalizedDateTime(
      $iso,
      self::INTL_DATE_SHORT,
      self::INTL_DATE_SHORT,
    );
  }

  /**
   * Formats an ISO time/datetime string using the active locale's time format.
   *
   * @param string $iso
   *   ISO time or datetime string, e.g. '14:30:00' or
   *   '2026-01-15T14:30:00Z'.
   *
   * @return string
   *   Formatted time string. Returns $iso unchanged if it cannot be parsed.
   */
  public function resolveTime(string $iso): string {
    return $this->resolveLocalizedDateTime(
      $iso,
      self::INTL_DATE_NONE,
      self::INTL_DATE_SHORT,
    );
  }

  /**
   * Formats a date-range array ({from, to}) with locale-formatted dates.
   *
   * @param array{from?: string|null, to?: string|null} $value
   *   The date-range array with optional 'from' and 'to' keys.
   *
   * @return array{from?: string|null, to?: string|null}
   *   The same array with date values formatted in the active locale.
   */
  public function resolveDateRange(array $value): array {
    $result = $value;
    if (isset($result['from']) && \is_string($result['from'])) {
      $result['from'] = $this->resolveDate($result['from']);
    }
    if (isset($result['to']) && \is_string($result['to'])) {
      $result['to'] = $this->resolveDate($result['to']);
    }
    return $result;
  }

  /**
   * Tries intl formatting first; falls back to the generated pattern map.
   *
   * Both paths produce a locale-appropriate short format. The intl path
   * delegates to ICU data (\IntlDateFormatter), which gives accurate results
   * for every locale. The fallback path uses PHP date() patterns generated
   * from ICU data (see DateFallbackPatterns), for environments (e.g. Drupal
   * CI) where ext-intl is not installed.
   *
   * @param string $iso
   *   ISO date/time string.
   * @param int $date_type
   *   \IntlDateFormatter date type constant (NONE or SHORT).
   * @param int $time_type
   *   \IntlDateFormatter time type constant (NONE or SHORT).
   *
   * @return string
   *   Formatted string, or $iso unchanged if parsing or formatting fails.
   */
  private function resolveLocalizedDateTime(
    string $iso,
    int $date_type,
    int $time_type,
  ): string {
    if ($iso === '') {
      return $iso;
    }
    try {
      // Parse in UTC so that ISO strings with a Z suffix (e.g. date-time props
      // stored by Canvas) and bare date strings both render at the same UTC
      // instant they represent.
      $date_time = new \DateTime($iso, new \DateTimeZone('UTC'));
    }
    catch (\Exception) {
      return $iso;
    }

    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    if ($this->isIntlAvailable()) {
      foreach (self::buildLocaleCandidates($langcode) as $locale) {
        try {
          $formatter = \IntlDateFormatter::create(
            $locale,
            $date_type,
            $time_type,
            'UTC',
          );
        }
        catch (\ValueError) {
          continue;
        }
        if (!$formatter instanceof \IntlDateFormatter) {
          continue;
        }
        try {
          $formatted = $formatter->format($date_time);
        }
        catch (\Error) {
          // Some ICU setups return a not-constructed formatter for unsupported
          // locales. Try the next candidate.
          continue;
        }
        if (\is_string($formatted)) {
          return $formatted;
        }
      }
    }

    $fallback = self::formatWithFallback(
      $date_time,
      $date_type,
      $time_type,
      $langcode,
    );
    return \is_string($fallback) ? $fallback : $iso;
  }

  /**
   * Returns whether the intl PHP extension is available.
   */
  private function isIntlAvailable(): bool {
    return $this->intlAvailable ?? \extension_loaded('intl');
  }

  /**
   * Formats a DateTime using the generated fallback patterns.
   *
   * Used when the intl extension is unavailable. Looks up the langcode, then
   * its base language ('pt-br' → 'pt'), in DateFallbackPatterns::PATTERNS.
   * Returns NULL for langcodes that are not listed there.
   *
   * @param \DateTimeInterface $date_time
   *   The date/time to format.
   * @param int $date_type
   *   IntlDateFormatter date type constant (NONE or SHORT).
   * @param int $time_type
   *   IntlDateFormatter time type constant (NONE or SHORT).
   * @param string $langcode
   *   The Drupal langcode.
   *
   * @return string|null
   *   Formatted string, or NULL if no pattern found for this locale.
   */
  private static function formatWithFallback(
    \DateTimeInterface $date_time,
    int $date_type,
    int $time_type,
    string $langcode,
  ): ?string {
    $format_key = match ([$date_type, $time_type]) {
      [self::INTL_DATE_SHORT, self::INTL_DATE_NONE] => 'date',
      [self::INTL_DATE_SHORT, self::INTL_DATE_SHORT] => 'date_time',
      [self::INTL_DATE_NONE, self::INTL_DATE_SHORT] => 'time',
      default => NULL,
    };
    if (!\is_string($format_key)) {
      return NULL;
    }

    $normalized = \strtolower($langcode);
    $base = \str_contains($normalized, '-')
      ? \explode('-', $normalized, 2)[0]
      : $normalized;

    $patterns = DateFallbackPatterns::PATTERNS[$normalized]
      ?? DateFallbackPatterns::PATTERNS[$base]
      ?? NULL;
    return $patterns === NULL ? NULL : $date_time->format($patterns[$format_key]);
  }

  /**
   * Builds a list of BCP 47 locale candidates from a Drupal langcode.
   *
   * Tries region-qualified, canonical (via Locale::canonicalize), and base
   * forms to maximize IntlDateFormatter compatibility.
   *
   * @param string $langcode
   *   Drupal langcode, e.g. 'en', 'fr', 'en-gb', 'zh-hans'.
   *
   * @return list<string>
   *   Ordered list of locale candidates to try.
   */
  private static function buildLocaleCandidates(string $langcode): array {
    $candidates = [];
    $add = static function (string $c) use (&$candidates): void {
      if ($c !== '' && !\in_array($c, $candidates, TRUE)) {
        $candidates[] = $c;
      }
    };
    $language = $langcode;
    $region = '';
    if (\str_contains($langcode, '-')) {
      [$language, $region] = \explode('-', $langcode, 2);
    }
    if ($region !== '') {
      $add($language . '_' . \strtoupper($region));
    }
    if (\class_exists(\Locale::class)) {
      $canonical = \Locale::canonicalize($langcode);
      if (\is_string($canonical)) {
        $add($canonical);
      }
    }
    $add($langcode);
    $add($language);
    return $candidates;
  }

}
