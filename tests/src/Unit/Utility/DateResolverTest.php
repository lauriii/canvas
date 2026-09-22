<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Unit\Utility;

use Drupal\canvas\Utility\DateFallbackPatterns;
use Drupal\canvas\Utility\DateResolver;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests Drupal\canvas\Utility\DateResolver.
 */
#[CoversClass(DateResolver::class)]
#[Group('canvas')]
final class DateResolverTest extends UnitTestCase {

  /**
   * Creates a DateResolver wired to a mock language manager.
   *
   * @param string $langcode
   *   The langcode the mock should return.
   * @param bool|null $intlAvailable
   *   NULL = auto-detect; TRUE = force intl path; FALSE = force fallback path.
   *
   * @return \Drupal\canvas\Utility\DateResolver
   *   A freshly constructed resolver.
   */
  private function makeResolver(
    string $langcode,
    ?bool $intlAvailable = NULL,
  ): DateResolver {
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn($langcode);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getCurrentLanguage')->willReturn($language);
    return new DateResolver($languageManager, $intlAvailable);
  }

  /**
   * Empty string passes through unchanged.
   */
  public function testResolveEmptyString(): void {
    $resolver = $this->makeResolver('en');
    self::assertSame('', $resolver->resolveDate(''));
    self::assertSame('', $resolver->resolveDateTime(''));
    self::assertSame('', $resolver->resolveTime(''));
  }

  /**
   * Invalid ISO string passes through unchanged.
   */
  public function testResolveInvalidIso(): void {
    $resolver = $this->makeResolver('en');
    self::assertSame('not-a-date', $resolver->resolveDate('not-a-date'));
    self::assertSame('not-a-date', $resolver->resolveDateTime('not-a-date'));
    self::assertSame('not-a-date', $resolver->resolveTime('not-a-date'));
  }

  /**
   * Fallback: English date uses n/j/y pattern.
   */
  #[DataProvider('fallbackDateProvider')]
  public function testResolveDateFallback(
    string $langcode,
    string $iso,
    string $expected,
  ): void {
    $result = $this->makeResolver($langcode, FALSE)->resolveDate($iso);
    self::assertSame($expected, $result);
  }

  /**
   * Data provider for fallback date tests.
   *
   * Each locale is tested with both a bare YYYY-MM-DD string (the canonical
   * stored format for format: date props) and a full RFC3339 datetime string.
   *
   * @return array<string, array{0: string, 1: string, 2: string}>
   */
  public static function fallbackDateProvider(): array {
    return [
      'en (n/j/y) bare date' => ['en', '2026-01-15', '1/15/26'],
      'en (n/j/y) full ISO' => ['en', '2026-01-15T14:30:00Z', '1/15/26'],
      'fr (d/m/Y) bare date' => ['fr', '2026-01-15', '15/01/2026'],
      'fr (d/m/Y) full ISO' => ['fr', '2026-01-15T14:30:00Z', '15/01/2026'],
      'ja (Y/m/d) bare date' => ['ja', '2026-01-15', '2026/01/15'],
      'ja (Y/m/d) full ISO' => ['ja', '2026-01-15T14:30:00Z', '2026/01/15'],
      'en-gb (d/m/Y) bare date' => ['en-gb', '2026-01-15', '15/01/2026'],
      'en-gb (d/m/Y) full ISO' => ['en-gb', '2026-01-15T14:30:00Z', '15/01/2026'],
      'de (d.m.y) bare date' => ['de', '2026-01-15', '15.01.26'],
      'ru (d.m.Y) bare date' => ['ru', '2026-01-15', '15.01.2026'],
      'nl (d-m-Y) bare date' => ['nl', '2026-01-15', '15-01-2026'],
      'hu (Y. m. d.) bare date' => ['hu', '2026-01-15', '2026. 01. 15.'],
      'zh-hans (Y/n/j) bare date' => ['zh-hans', '2026-01-15', '2026/1/15'],
      'pt-br uses its own row, not pt' => ['pt-br', '2026-01-15', '15/01/2026'],
      'pt-pt uses its own row, not pt' => ['pt-pt', '2026-01-15', '15/01/26'],
      'en-ca falls back to en' => ['en-ca', '2026-01-15', '1/15/26'],
      'FR is matched case-insensitively' => ['FR', '2026-01-15', '15/01/2026'],
    ];
  }

  /**
   * Fallback: English datetime uses n/j/y, g:i A pattern.
   */
  public function testResolveDateTimeFallbackEn(): void {
    $result = $this->makeResolver('en', FALSE)
      ->resolveDateTime('2026-01-15T14:30:00');
    self::assertSame('1/15/26, 2:30 PM', $result);
  }

  /**
   * Fallback: French datetime uses d/m/Y H:i pattern.
   */
  public function testResolveDateTimeFallbackFr(): void {
    $result = $this->makeResolver('fr', FALSE)
      ->resolveDateTime('2026-01-15T14:30:00');
    self::assertSame('15/01/2026 14:30', $result);
  }

  /**
   * Fallback: English time uses g:i A pattern, from a full datetime string.
   */
  public function testResolveTimeFallbackEn(): void {
    $result = $this->makeResolver('en', FALSE)
      ->resolveTime('2026-01-15T14:30:00');
    self::assertSame('2:30 PM', $result);
  }

  /**
   * Fallback: French time uses H:i pattern, from a full datetime string.
   */
  public function testResolveTimeFallbackFr(): void {
    $result = $this->makeResolver('fr', FALSE)
      ->resolveTime('2026-01-15T14:30:00');
    self::assertSame('14:30', $result);
  }

  /**
   * Fallback: bare HH:MM:SS time string is formatted correctly.
   *
   * There are currently no Canvas prop types that produce a bare time value.
   * This test documents the intended use case for when a format: time prop
   * type is introduced: a bare HH:MM:SS string passed to resolveTime() should
   * produce a locale-formatted time, not be returned unchanged.
   */
  public function testResolveTimeFallbackBareTimeString(): void {
    $en_result = $this->makeResolver('en', FALSE)->resolveTime('14:30:00');
    self::assertSame('2:30 PM', $en_result);

    $fr_result = $this->makeResolver('fr', FALSE)->resolveTime('14:30:00');
    self::assertSame('14:30', $fr_result);
  }

  /**
   * Intl path: bare HH:MM:SS time string is formatted correctly.
   *
   * There are currently no Canvas prop types that produce a bare time value.
   * This test documents the intended use case for when a format: time prop
   * type is introduced.
   */
  public function testResolveTimeBareTimeStringIntl(): void {
    if (!\extension_loaded('intl')) {
      $this->markTestSkipped('intl extension not loaded.');
    }
    $result = $this->makeResolver('en')->resolveTime('14:30:00');
    // Exact output is ICU-version-dependent; assert formatting happened.
    self::assertNotSame('14:30:00', $result);
    self::assertNotEmpty($result);
  }

  /**
   * Fallback: date-range formats both fields.
   */
  public function testResolveDateRangeFallback(): void {
    $result = $this->makeResolver('en', FALSE)->resolveDateRange([
      'from' => '2026-01-15',
      'to' => '2026-03-20',
    ]);
    self::assertArrayHasKey('from', $result);
    self::assertArrayHasKey('to', $result);
    self::assertSame('1/15/26', $result['from']);
    self::assertSame('3/20/26', $result['to']);
  }

  /**
   * Fallback: date-range handles null and absent fields without error.
   */
  public function testResolveDateRangeFallbackNullField(): void {
    $result = $this->makeResolver('en', FALSE)->resolveDateRange([
      'from' => '2026-01-15',
      'to' => NULL,
    ]);
    self::assertArrayHasKey('from', $result);
    self::assertArrayHasKey('to', $result);
    self::assertSame('1/15/26', $result['from']);
    self::assertNull($result['to']);
  }

  /**
   * Fallback: unknown locale returns the ISO string unchanged.
   */
  public function testResolveDateFallbackUnknownLocale(): void {
    $result = $this->makeResolver('xx', FALSE)->resolveDate('2026-01-15');
    self::assertSame('2026-01-15', $result);
  }

  /**
   * Fallback: langcodes that date() cannot reproduce are not in the table.
   *
   * Korean needs localized AM/PM text, Arabic needs native digits. Both return
   * the ISO string unchanged instead of a wrong format.
   */
  public function testResolveDateFallbackUnsupportedLocale(): void {
    self::assertArrayNotHasKey('ko', DateFallbackPatterns::PATTERNS);
    self::assertArrayNotHasKey('ar', DateFallbackPatterns::PATTERNS);
    self::assertSame('2026-01-15', $this->makeResolver('ko', FALSE)->resolveDate('2026-01-15'));
    self::assertSame('2026-01-15', $this->makeResolver('ar', FALSE)->resolveDate('2026-01-15'));
  }

  /**
   * The fallback path produces the same output as the intl path.
   *
   * Protects the generated DateFallbackPatterns::PATTERNS. Runs only with the
   * ICU version the patterns were generated from, because ICU short patterns
   * change between versions.
   */
  public function testFallbackMatchesIntl(): void {
    if (!\extension_loaded('intl')) {
      $this->markTestSkipped('intl extension not loaded.');
    }
    if (!self::matchesGeneratedIcuVersion()) {
      $this->markTestSkipped(\sprintf('Patterns were generated with ICU %s, this PHP has ICU %s.', DateFallbackPatterns::ICU_VERSION, INTL_ICU_VERSION));
    }
    // Morning and afternoon, 1-digit and 2-digit day, month and hour,
    // midnight and noon.
    $samples = [
      '2026-01-05T09:05:00Z',
      '2026-11-25T14:30:00Z',
      '2026-07-04T00:00:00Z',
      '2026-03-09T12:00:00Z',
    ];
    foreach (\array_keys(DateFallbackPatterns::PATTERNS) as $langcode) {
      $intl = $this->makeResolver($langcode, TRUE);
      $fallback = $this->makeResolver($langcode, FALSE);
      foreach ($samples as $iso) {
        foreach (['resolveDate', 'resolveDateTime', 'resolveTime'] as $method) {
          // ICU uses U+202F and U+00A0 where date() uses a normal space.
          $expected = \str_replace(["\u{202f}", "\u{a0}"], ' ', $intl->$method($iso));
          self::assertSame($expected, $fallback->$method($iso), "$langcode $method($iso)");
        }
      }
    }
  }

  /**
   * Intl path: resolveDate produces a locale-formatted value.
   */
  #[DataProvider('intlLocaleProvider')]
  public function testResolveDateIntl(string $langcode): void {
    if (!\extension_loaded('intl')) {
      $this->markTestSkipped('intl extension not loaded.');
    }
    $result = $this->makeResolver($langcode)->resolveDate('2026-01-15');
    self::assertNotSame('2026-01-15', $result);
    self::assertNotEmpty($result);
  }

  /**
   * Data provider for intl-path locale tests.
   *
   * @return array<string, array{0: string}>
   */
  public static function intlLocaleProvider(): array {
    return [
      'en' => ['en'],
      'fr' => ['fr'],
      'ja' => ['ja'],
      'en-gb' => ['en-gb'],
    ];
  }

  private static function matchesGeneratedIcuVersion(): bool {
    $intl_version = \phpversion('intl');
    if (!\is_string($intl_version)) {
      return FALSE;
    }
    return \version_compare($intl_version, DateFallbackPatterns::ICU_VERSION, '==');
  }

}
