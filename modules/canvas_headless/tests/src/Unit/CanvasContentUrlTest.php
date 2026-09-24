<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_headless\Unit;

use Drupal\canvas_headless\CanvasContentUrl;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests shared normalization of redirect and translation URLs.
 */
#[CoversClass(CanvasContentUrl::class)]
#[Group('canvas_headless')]
final class CanvasContentUrlTest extends UnitTestCase {

  /**
   * Tests installation paths, queries, and external URLs.
   */
  #[DataProvider('urls')]
  public function testNormalize(string $url, string $expected): void {
    $request = Request::create('https://example.com/drupal/fr/page/1', server: [
      'SCRIPT_NAME' => '/drupal/index.php',
      'SCRIPT_FILENAME' => '/var/www/drupal/index.php',
      'PHP_SELF' => '/drupal/index.php',
    ]);
    self::assertSame('/drupal', $request->getBasePath());
    self::assertSame($expected, CanvasContentUrl::normalize($url, $request));
  }

  /**
   * Provides absolute generated URLs and existing relative redirect targets.
   */
  public static function urls(): iterable {
    yield ['https://example.com/drupal/fr/bonjour?language=fr', '/fr/bonjour?language=fr'];
    yield ['https://example.com/drupal/', '/'];
    yield ['https://example.com/drupal', 'https://example.com/drupal'];
    yield ['https://fr.example.com/drupal/bonjour', 'https://fr.example.com/drupal/bonjour'];
    yield ['https://example.com/elsewhere', 'https://example.com/elsewhere'];
    yield ['/drupal/relative', '/drupal/relative'];
    yield ['https://example.com/drupal/fr/page#section', '/fr/page#section'];
  }

}
