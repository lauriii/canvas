<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas_ssr\Unit\JsRunner;

use Drupal\canvas_ssr\JsRunner\ModuleUrl;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[CoversClass(ModuleUrl::class)]
#[Group('canvas_ssr')]
final class ModuleUrlTest extends UnitTestCase {

  /**
   * Tests rooted paths and absolute URLs are module URLs.
   */
  #[DataProvider('moduleUrls')]
  public function testModuleUrlsPass(string $url): void {
    ModuleUrl::assert($url);
    $this->addToAssertionCount(1);
  }

  /**
   * Tests anything else is refused.
   */
  #[DataProvider('notModuleUrls')]
  public function testOtherNamesAreRefused(string $name): void {
    $this->expectException(\InvalidArgumentException::class);
    ModuleUrl::assert($name);
  }

  /**
   * Module URLs.
   *
   * @return array<string, array{string}>
   *   Each URL.
   */
  public static function moduleUrls(): array {
    return [
      'rooted' => ['/modules/contrib/canvas/dist/preact.js'],
      'absolute' => ['https://cdn.example.com/files/a.js'],
      'with a port' => ['http://127.0.0.1:8080/a.js'],
      'protocol-relative' => ['//cdn.example.com/a.js'],
    ];
  }

  /**
   * Names that aren't module URLs.
   *
   * @return array<string, array{string}>
   *   Each name.
   */
  public static function notModuleUrls(): array {
    return [
      'bare' => ['preact'],
      'relative' => ['./a.js'],
      'empty' => [''],
      'no authority' => ['data:text/javascript,export default 1'],
    ];
  }

}
