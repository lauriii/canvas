<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\DrupalSettings;

use Drupal\canvas_ssr\DrupalSettings\AllProvidedData;
use Drupal\canvas_ssr\DrupalSettings\DataValue;
use Drupal\canvas_ssr\DrupalSettings\Provider\PageDataProviderInterface;
use Drupal\canvas_ssr\DrupalSettings\Provider\SiteDataProviderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests merging every provider's site data and page data.
 */
#[CoversClass(AllProvidedData::class)]
#[CoversClass(DataValue::class)]
#[Group('canvas_ssr')]
final class AllProvidedDataTest extends UnitTestCase {

  /**
   * Tests listing values builds none, and a value is built once.
   */
  public function testValuesAreBuiltLazily(): void {
    // An object, not a by-reference int: PHPStan can't see the closure run,
    // and would think the count is always 0.
    $counter = new class() {
      public int $builds = 0;
    };
    $value = new DataValue(
      function () use ($counter): array {
        $counter->builds++;
        return ['x' => 1];
      },
      static fn(): CacheableMetadata => (new CacheableMetadata())->addCacheTags(['test:1']),
    );
    $provided = new AllProvidedData([self::site(['v0.test' => $value])], []);

    self::assertSame(['v0.test'], $provided->keys());
    self::assertSame(['v0.test' => $value], $provided->siteData());
    self::assertSame(0, $counter->builds);

    self::assertSame(['x' => 1], $value->getValue());
    self::assertSame(['x' => 1], $value->getValue());
    self::assertSame(1, $counter->builds);
    self::assertSame(['test:1'], $value->getCacheTags());
  }

  /**
   * Tests the first provider of each kind to give a key wins it.
   *
   * In the container, provider order is tag priority, highest first.
   */
  public function testFirstProviderWinsEachKey(): void {
    $first = self::value();
    $shadowed = self::value();
    $first_page = self::value();
    $shadowed_page = self::value();
    $provided = new AllProvidedData(
      [self::site(['v0.a' => $first]), self::site(['v0.a' => $shadowed, 'v0.b' => $shadowed])],
      [self::page(['v0.c' => $first_page]), self::page(['v0.c' => $shadowed_page])],
    );

    self::assertSame(['v0.a' => $first, 'v0.b' => $shadowed], $provided->siteData());
    self::assertSame(['v0.c' => $first_page], $provided->pageData());
    self::assertSame(['v0.a', 'v0.b', 'v0.c'], $provided->keys());
  }

  /**
   * Tests a key a page data provider gives is page data.
   *
   * So a site can make a value Canvas treats as site data vary by page.
   */
  public function testPageDataWinsOverSiteData(): void {
    $site = self::value();
    $page = self::value();
    $provided = new AllProvidedData([self::site(['v0.a' => $site, 'v0.b' => $site])], [self::page(['v0.a' => $page])]);

    self::assertSame(['v0.b' => $site], $provided->siteData());
    self::assertSame(['v0.a' => $page], $provided->pageData());
  }

  /**
   * Returns a value that builds nothing.
   */
  private static function value(): DataValue {
    return new DataValue(static fn(): array => [], static fn() => new CacheableMetadata());
  }

  /**
   * Returns a site data provider of the given values.
   *
   * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $values
   *   The values.
   */
  private static function site(array $values): SiteDataProviderInterface {
    return new class($values) implements SiteDataProviderInterface {

      /**
       * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $values
       *   The values.
       */
      public function __construct(private readonly array $values) {}

      /**
       * {@inheritdoc}
       */
      public function getSiteData(): array {
        return $this->values;
      }

    };
  }

  /**
   * Returns a page data provider of the given values.
   *
   * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $values
   *   The values.
   */
  private static function page(array $values): PageDataProviderInterface {
    return new class($values) implements PageDataProviderInterface {

      /**
       * @param array<string, \Drupal\canvas_ssr\DrupalSettings\DataValue> $values
       *   The values.
       */
      public function __construct(private readonly array $values) {}

      /**
       * {@inheritdoc}
       */
      public function getPageData(): array {
        return $this->values;
      }

    };
  }

}
